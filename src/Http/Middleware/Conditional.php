<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Http\Middleware;

use Closure;
use ExpertSystems\ConditionalRequests\ConditionalRequests;
use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
}
