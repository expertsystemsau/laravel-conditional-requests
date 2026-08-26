<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Exceptions\LockTimeoutException;
use ExpertSystems\ConditionalRequests\Locking\LockWait;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\FakePdoException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

function lockWaitFixture(): LockWait
{
    return new LockWait;
}

function lockPdoException(string $message, string $sqlState): QueryException
{
    // PDOException::$code is protected and its constructor takes an int, so a
    // SQLSTATE string can only be set from inside a subclass — hence the
    // fixture. QueryException then copies it onto itself.
    return new QueryException('testing', 'select 1', [], new FakePdoException($message, $sqlState));
}

it('exposes the configured default', function (): void {
    expect(config('laravel-conditional-requests.lock_timeout'))->toBe(5);
});

it('bounds a postgres lock wait inside the transaction, where it reverts itself', function (): void {
    $statements = lockWaitFixture()->statements('pgsql', 5);

    expect($statements['before'])->toBe([])
        ->and($statements['inside'])->toBe(["set local lock_timeout = '5s'"])
        ->and($statements['after'])->toBe([]);
});

it('bounds a mysql lock wait around the transaction and puts the session back', function (): void {
    $statements = lockWaitFixture()->statements('mysql', 5);

    expect($statements['before'])->toBe([
        'set @laravel_conditional_requests_lock_timeout = @@session.innodb_lock_wait_timeout',
        'set session innodb_lock_wait_timeout = 5',
    ])
        ->and($statements['inside'])->toBe([])
        ->and($statements['after'])->toBe([
            'set session innodb_lock_wait_timeout = @laravel_conditional_requests_lock_timeout',
        ]);
});

it('treats mariadb as mysql', function (): void {
    expect(lockWaitFixture()->statements('mariadb', 3))->toBe(lockWaitFixture()->statements('mysql', 3));
});

it('never leaves a mysql session retuned', function (): void {
    // Every statement that changes the session has a matching one that puts it
    // back. Under Octane or a persistent PDO the connection outlives the
    // request, and a leaked innodb_lock_wait_timeout would silently apply to
    // every later query on it.
    expect(lockWaitFixture()->statements('mysql', 5)['after'])->not->toBe([]);
});

it('has nothing to say to a driver without row locks', function (): void {
    foreach (['sqlite', 'sqlsrv', 'somethingelse'] as $driver) {
        expect(lockWaitFixture()->statements($driver, 5))
            ->toBe(['before' => [], 'inside' => [], 'after' => []]);
    }
});

it('leaves the servers own setting alone when the timeout is zero or negative', function (): void {
    foreach ([0, -1] as $seconds) {
        expect(lockWaitFixture()->statements('pgsql', $seconds))
            ->toBe(['before' => [], 'inside' => [], 'after' => []])
            ->and(lockWaitFixture()->statements('mysql', $seconds))
            ->toBe(['before' => [], 'inside' => [], 'after' => []]);
    }
});

it('runs the callback in a transaction and returns what it returned', function (): void {
    $level = null;

    $result = lockWaitFixture()->transaction(DB::connection(), 5, function () use (&$level): string {
        $level = DB::connection()->transactionLevel();

        return 'done';
    });

    expect($result)->toBe('done')
        ->and($level)->toBe(1)
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('issues no session statements at all on sqlite', function (): void {
    $seen = [];

    DB::listen(function ($query) use (&$seen): void {
        $seen[] = $query->sql;
    });

    lockWaitFixture()->transaction(DB::connection(), 5, fn (): string => 'done');

    expect($seen)->toBe([]);
});

it('rolls back and rethrows unchanged when the callback throws', function (): void {
    expect(fn (): mixed => lockWaitFixture()->transaction(
        DB::connection(),
        5,
        fn (): mixed => throw new RuntimeException('nope'),
    ))->toThrow(RuntimeException::class, 'nope')
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('recognises a mysql lock wait timeout', function (): void {
    expect(lockWaitFixture()->caused(lockPdoException(
        'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction',
        'HY000',
    )))->toBeTrue();
});

it('recognises a postgres lock timeout, which the framework detector does not', function (): void {
    expect(lockWaitFixture()->caused(lockPdoException(
        'SQLSTATE[55P03]: Lock not available: 7 ERROR:  canceling statement due to lock timeout',
        '55P03',
    )))->toBeTrue();
});

it('recognises a deadlock as the same failure from the clients side', function (): void {
    expect(lockWaitFixture()->caused(lockPdoException('Deadlock found when trying to get lock', '40001')))->toBeTrue()
        ->and(lockWaitFixture()->caused(new DeadlockException('deadlock detected')))->toBeTrue();
});

it('recognises a sql server lock timeout', function (): void {
    expect(lockWaitFixture()->caused(lockPdoException(
        'SQLSTATE[HY000]: Lock request time out period exceeded.',
        'HY000',
    )))->toBeTrue();
});

it('does not mistake an ordinary query failure for contention', function (): void {
    expect(lockWaitFixture()->caused(lockPdoException('no such table: articles', 'HY000')))->toBeFalse()
        ->and(lockWaitFixture()->caused(new RuntimeException('something else')))->toBeFalse();
});

it('reports 503 with a Retry-After for a lock that could not be taken', function (): void {
    $exception = new LockTimeoutException('busy');

    expect($exception->getStatusCode())->toBe(503)
        ->and($exception->getHeaders())->toHaveKey('Retry-After')
        ->and($exception->getMessage())->toBe('busy');
});

it('renders a lock timeout through the applications existing handler', function (): void {
    expect(new LockTimeoutException)->toBeInstanceOf(HttpException::class)
        ->and(new LockTimeoutException)->toBeInstanceOf(ServiceUnavailableHttpException::class);
});

it('names a translation key that resolves to real copy', function (): void {
    expect(trans(LockTimeoutException::MESSAGE_KEY))
        ->not->toBe(LockTimeoutException::MESSAGE_KEY)
        ->and(trans(LockTimeoutException::MESSAGE_KEY))->toContain('retry');
});
