<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Http\Middleware;

use Closure;
use ExpertSystems\ConditionalRequests\ConditionalRequests;
use ExpertSystems\ConditionalRequests\Contracts\RequestValidatorStrategy;
use ExpertSystems\ConditionalRequests\Contracts\ValidatorStrategy;
use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Handles RFC 9110 conditional requests for a route.
 */
final readonly class Conditional
{
    public function __construct(
        private ConditionalRequests $registry,
        private Repository $config,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$flags): Response
    {
        // Everything that can be decided from the request alone is decided here,
        // before the request is touched, so `enabled => false` really is a
        // pass-through and the controller sees the method the client sent.
        if (! $this->requestEligible($request)) {
            return $next($request);
        }

        // array_values() looks like a no-op and is one at runtime, but PHPStan
        // types a `string ...$flags` variadic as array<int<0,max>|string,
        // string> — a variadic can receive named arguments — so it is needed
        // to satisfy the list<string> parameter below. Do not remove it.
        $strategy = $this->registry->strategy($this->strategyName(array_values($flags)));

        // A strategy that can answer from the request alone changes two things:
        // the validator is known before the controller runs, and the rendered
        // body is never needed — so neither the HEAD method mutation nor the
        // body-shaped skip rules apply to it.
        $requestDerived = $strategy instanceof RequestValidatorStrategy;
        $validator = $requestDerived ? $strategy->fromRequest($request) : null;

        if ($validator instanceof Validator) {
            $notModified = $this->notModified($request, $validator);

            if ($notModified instanceof Response) {
                return $notModified;
            }
        }

        $originalMethod = $request->getMethod();
        $isHead = $originalMethod === 'HEAD';
        $mutate = $isHead && ! $requestDerived;

        // Router::runRouteWithinStack()'s pipeline destination is
        // prepareResponse() (Router.php:821), which runs before route middleware
        // regains control, and Symfony's Response::prepare() nulls the body for
        // HEAD there. Present the request to the controller as a GET so there is
        // a body left to hash, then re-empty the response ourselves afterwards.
        if ($mutate) {
            $request->setMethod('GET');
        }

        try {
            $response = $next($request);
        } finally {
            if ($mutate) {
                $request->setMethod($originalMethod);
            }
        }

        // The exclusion is evaluated a second time on purpose. Under kernel-global
        // placement the first check ran before routing, where $request->route() is
        // null and Request::routeIs() answers false for every pattern, so a
        // route-name exclusion could only ever be honoured here. The pre-$next()
        // check still earns its place: it keeps a URI-excluded route a true
        // pass-through with no request mutation at all.
        if (! $this->excluded($request) && $this->eligible($response, $requestDerived)) {
            $this->attach($request, $response, $strategy, $validator);
        }

        // Single exit, so every path applies the HEAD nulling. Under route or
        // group placement Router::runRoute()'s own prepareResponse (Router.php:799)
        // would do it again harmlessly; under global placement nothing else does.
        return $isHead ? $this->withoutBody($request, $response) : $response;
    }

    /**
     * Attach a validator and let Symfony decide whether it is still current.
     *
     * A validator already computed from the request is reused rather than
     * derived a second time: it is the same answer, and reusing it guarantees
     * the tag on this response is the one the short-circuit will compare
     * against on the next request.
     */
    private function attach(Request $request, Response $response, ValidatorStrategy $strategy, ?Validator $known): void
    {
        $validator = $known ?? $strategy->fromResponse($request, $response);

        if (! $validator instanceof Validator) {
            return;
        }

        $response->setEtag($validator->etag, $validator->weak);

        // Symfony performs the RFC 9110 comparison and, on a match, mutates the
        // response into a compliant 304 — status, empty body, stripped headers.
        $response->isNotModified($request);
    }

    /**
     * A complete 304 for a validator known before the controller ran, or null
     * when the client's tags do not match it and the controller has to run.
     *
     * Symfony owns the comparison here exactly as it does after the controller,
     * and setNotModified() strips the response back to a compliant 304 —
     * status, empty body, forbidden headers removed, ETag kept. Laravel's
     * Router::toResponse() then normalises any 304 the same way whichever path
     * produced it, which is what makes the two indistinguishable to a client.
     *
     * Laravel's own Response rather than Symfony's: toResponse() passes any
     * SymfonyResponse straight through unwrapped, so a bare one would reach
     * outer middleware — and the test suite — missing every convenience
     * ResponseTrait adds, status() and header() among them.
     */
    private function notModified(Request $request, Validator $validator): ?Response
    {
        $response = new IlluminateResponse;
        $response->setEtag($validator->etag, $validator->weak);

        return $response->isNotModified($request) ? $response : null;
    }

    /**
     * Empty a response body while preserving the length it advertised.
     *
     * Mirrors what Symfony's Response::prepare() does for a HEAD request.
     */
    private function withoutBody(Request $request, Response $response): Response
    {
        // setContent(null) is a no-op on a BinaryFileResponse — its body is a
        // file read at send time, suppressed by prepare() zeroing the read
        // length for a HEAD request. That length is protected with no setter, so
        // re-preparing against the restored method is how it gets zeroed: the
        // same call Router::prepareResponse() already makes, this time seeing
        // the method the client actually sent rather than our temporary GET.
        if ($response instanceof BinaryFileResponse) {
            return $response->prepare($request);
        }

        $length = $response->headers->get('Content-Length');

        $response->setContent(null);

        if ($length !== null) {
            $response->headers->set('Content-Length', $length);
        }

        return $response;
    }

    /**
     * The strategy this route asked for, or the configured default.
     *
     * @param  list<string>  $flags
     */
    private function strategyName(array $flags): string
    {
        return Flags::parse($flags)->strategyOr(
            (string) $this->config->get('laravel-conditional-requests.strategy'),
        );
    }

    /**
     * Whether this request should take part in the read path at all.
     *
     * Request-shaped only: no response is needed, which is what lets it run
     * before the controller and gate the pre-controller short-circuit.
     */
    private function requestEligible(Request $request): bool
    {
        if (! (bool) $this->config->get('laravel-conditional-requests.enabled')) {
            return false;
        }

        if (! in_array($request->getMethod(), $this->methods(), strict: true)) {
            return false;
        }

        return ! $this->excluded($request);
    }

    /**
     * Whether this response can carry a validator.
     *
     * The stream, binary, and size rules all exist for one reason — a validator
     * derived from the body means reading the whole body. They do not apply to
     * a validator derived from the request, which costs nothing whatever the
     * response turned out to be.
     */
    private function eligible(Response $response, bool $requestDerived): bool
    {
        if (! $response->isSuccessful() || $response->getEtag() !== null) {
            return false;
        }

        if ($requestDerived) {
            return true;
        }

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        $content = $response->getContent();

        if ($content === false) {
            return false;
        }

        $ceiling = (int) $this->config->get('laravel-conditional-requests.max_response_bytes');

        return $ceiling <= 0 || strlen($content) <= $ceiling;
    }

    /**
     * @return list<string>
     */
    private function methods(): array
    {
        return array_map(strtoupper(...), $this->stringList('methods'));
    }

    /**
     * Match the request against the configured route-name and URI exclusions.
     */
    private function excluded(Request $request): bool
    {
        $patterns = $this->stringList('exclude');

        if ($patterns === []) {
            return false;
        }

        return $request->routeIs(...$patterns) || $request->is(...$patterns);
    }

    /**
     * Read a config key as a list of strings, discarding anything that is not one.
     *
     * A published config can hold a bare string where a list is documented; that
     * degrades to a no-op here rather than a TypeError deep in the stack.
     *
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        $values = [];
        $configured = $this->config->get("laravel-conditional-requests.{$key}");

        foreach (is_iterable($configured) ? $configured : [$configured] as $value) {
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
