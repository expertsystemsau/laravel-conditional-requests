<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Records the transaction depth it was executed at.
 *
 * ShouldQueue is required, not decorative: a job that does not implement it is
 * run inline by the bus and never reaches the queue, so afterCommit() — the
 * mitigation this fixture exists to demonstrate — would never be consulted.
 */
final class RecordsTransactionLevel implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @var list<int>
     */
    public static array $levels = [];

    public function handle(): void
    {
        self::$levels[] = DB::connection()->transactionLevel();
    }
}
