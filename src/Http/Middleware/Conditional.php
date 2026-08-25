<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Http\Middleware;

use Closure;
use ExpertSystems\ConditionalRequests\ConditionalRequests;
use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
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

        $originalMethod = $request->getMethod();
        $isHead = $originalMethod === 'HEAD';

        // Router::runRouteWithinStack()'s pipeline destination is
        // prepareResponse() (Router.php:821), which runs before route middleware
        // regains control, and Symfony's Response::prepare() nulls the body for
        // HEAD there. Present the request to the controller as a GET so there is
        // a body left to hash, then re-empty the response ourselves afterwards.
        if ($isHead) {
            $request->setMethod('GET');
        }

        try {
            $response = $next($request);
        } finally {
            if ($isHead) {
                $request->setMethod($originalMethod);
            }
        }

        // The exclusion is evaluated a second time on purpose. Under kernel-global
        // placement the first check ran before routing, where $request->route() is
        // null and Request::routeIs() answers false for every pattern, so a
        // route-name exclusion could only ever be honoured here. The pre-$next()
        // check still earns its place: it keeps a URI-excluded route a true
        // pass-through with no request mutation at all.
        if (! $this->excluded($request) && $this->eligible($response)) {
            // array_values() looks like a no-op and is one at runtime, but PHPStan
            // types a `string ...$flags` variadic as array<int<0,max>|string,
            // string> — a variadic can receive named arguments — so it is needed
            // to satisfy the list<string> parameter below. Do not remove it.
            $this->attach($request, $response, array_values($flags));
        }

        // Single exit, so every path applies the HEAD nulling. Under route or
        // group placement Router::runRoute()'s own prepareResponse (Router.php:799)
        // would do it again harmlessly; under global placement nothing else does.
        return $isHead ? $this->withoutBody($request, $response) : $response;
    }

    /**
     * Attach a validator and let Symfony decide whether it is still current.
     *
     * @param  list<string>  $flags
     */
    private function attach(Request $request, Response $response, array $flags): void
    {
        $validator = $this->registry
            ->strategy($this->strategyName($flags))
            ->fromResponse($request, $response);

        if (! $validator instanceof Validator) {
            return;
        }

        $response->setEtag($validator->etag, $validator->weak);

        // Symfony performs the RFC 9110 comparison and, on a match, mutates the
        // response into a compliant 304 — status, empty body, stripped headers.
        $response->isNotModified($request);
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
     * before the controller and, in v0.4, gate the pre-controller short-circuit.
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
     */
    private function eligible(Response $response): bool
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        if (! $response->isSuccessful() || $response->getEtag() !== null) {
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
