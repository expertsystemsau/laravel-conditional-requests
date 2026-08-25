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
        $response = $next($request);

        if (! $this->eligible($request, $response)) {
            return $response;
        }

        $validator = $this->registry
            ->strategy($this->strategyName(array_values($flags)))
            ->fromResponse($request, $response);

        if (! $validator instanceof Validator) {
            return $response;
        }

        $response->setEtag($validator->etag, $validator->weak);

        // Symfony performs the RFC 9110 comparison and, on a match, mutates the
        // response into a compliant 304 — status, empty body, stripped headers.
        $response->isNotModified($request);

        return $response;
    }

    /**
     * The first flag naming a strategy wins; otherwise fall back to config.
     *
     * @param  list<string>  $flags
     */
    private function strategyName(array $flags): string
    {
        foreach ($flags as $flag) {
            if ($flag !== '') {
                return $flag;
            }
        }

        return (string) $this->config->get('laravel-conditional-requests.strategy', 'body');
    }

    /**
     * Whether this request and response should take part in the read path.
     */
    private function eligible(Request $request, Response $response): bool
    {
        if ($this->config->get('laravel-conditional-requests.enabled', true) !== true) {
            return false;
        }

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        if (! $response->isSuccessful() || $response->getEtag() !== null) {
            return false;
        }

        if (! in_array($request->getMethod(), $this->methods(), strict: true)) {
            return false;
        }

        if ($this->excluded($request)) {
            return false;
        }

        $content = $response->getContent();

        if ($content === false) {
            return false;
        }

        $ceiling = (int) $this->config->get('laravel-conditional-requests.max_response_bytes', 1_048_576);

        return $ceiling <= 0 || strlen($content) <= $ceiling;
    }

    /**
     * @return list<string>
     */
    private function methods(): array
    {
        /** @var list<string> $methods */
        $methods = $this->config->get('laravel-conditional-requests.methods', ['GET', 'HEAD']);

        return array_map(strtoupper(...), $methods);
    }

    /**
     * Match the request against the configured route-name and URI exclusions.
     */
    private function excluded(Request $request): bool
    {
        /** @var list<string> $patterns */
        $patterns = $this->config->get('laravel-conditional-requests.exclude', []);

        if ($patterns === []) {
            return false;
        }

        return $request->routeIs(...$patterns) || $request->is(...$patterns);
    }
}
