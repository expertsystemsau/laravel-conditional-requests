<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records the transaction depth once the middleware it wraps has returned.
 *
 * A class rather than a closure because Route::middleware() casts every entry
 * to a string (Route.php:1090), so a closure cannot be placed in a route's
 * pipeline at all. Placed outside `conditional`, this is what proves the lock
 * is released before the response leaves the middleware rather than merely
 * before the test ends.
 */
final class ObservesTransactionLevel
{
    /**
     * @var list<int>
     */
    public static array $levels = [];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        self::$levels[] = DB::connection()->transactionLevel();

        return $response;
    }
}
