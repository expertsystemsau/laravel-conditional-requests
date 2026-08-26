<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records that a request reached this point in the pipeline.
 *
 * Sits between a globally pushed `conditional` and a route's own guard, which
 * is what tells two identical 412s apart: the global instance refusing on the
 * route's behalf never lets the request this far, and the route-level guard
 * refusing means it did. Reset the counter in the test that reads it.
 */
class Probe
{
    public static int $reached = 0;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        self::$reached++;

        return $next($request);
    }
}
