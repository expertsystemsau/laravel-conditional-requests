<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Locking;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything about waiting for a row lock: how long to wait, and what it means
 * when the wait does not end well.
 *
 * The two halves belong together because they are two views of one event. The
 * statements below are what bounds the wait; caused() is what recognises the
 * error the server raises when that bound is reached — or when the server's own
 * bound is reached, because the bound is opt-in and most deployments will never
 * set one.
 */
final readonly class LockWait
{
    use DetectsConcurrencyErrors;

    /**
     * Run $callback inside a transaction on $connection, with the lock wait
     * bounded where the driver supports it.
     *
     * Laravel's Connection::transaction() is used with its default of one
     * attempt. It can retry a callback that failed on a concurrency error, but
     * this callback runs the controller, and re-running arbitrary application
     * code — its dispatched jobs, its side effects, its mail — to recover from
     * a lock wait is a worse outcome than the 503 the caller turns this into.
     *
     * The bound is issued only when this transaction is the outermost one. If
     * something already opened a transaction on the connection before the
     * middleware ran — an application-level wrapper, or a test's own
     * DatabaseTransactions — ours becomes a savepoint, the row lock is held
     * until that outer commit rather than ours, and Postgres' SET LOCAL would
     * outlive the savepoint and go on bounding every later statement in the
     * outer transaction. Retuning a transaction the package does not own is the
     * worse of the two, so the wait is left at the server's setting there. The
     * re-read, the FOR UPDATE, and the second evaluation are unaffected.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function transaction(Connection $connection, int $seconds, Closure $callback): mixed
    {
        $statements = $connection->transactionLevel() === 0
            ? $this->statements($connection->getDriverName(), $seconds)
            : ['before' => [], 'inside' => [], 'after' => []];

        foreach ($statements['before'] as $statement) {
            $connection->statement($statement);
        }

        try {
            return $connection->transaction(function () use ($connection, $statements, $callback): mixed {
                foreach ($statements['inside'] as $statement) {
                    $connection->statement($statement);
                }

                return $callback();
            });
        } finally {
            try {
                foreach ($statements['after'] as $statement) {
                    $connection->statement($statement);
                }
            } catch (Throwable) {
                // A restore that fails must not become the exception the caller
                // sees. The realistic case is a connection that died inside the
                // transaction: the rollback leaves transactionLevel at 0, so
                // Laravel silently reconnects for the statement below, and a
                // reconnect that then fails outright would replace the real
                // error with its own. The statement is itself written to
                // survive the reconnect that succeeds — see statements().
            }
        }
    }

    /**
     * The statements that bound a lock wait on a given driver, split by where
     * they have to run.
     *
     * Postgres has SET LOCAL, which is scoped to the transaction and reverts
     * itself at commit or rollback — one statement, no restore, and nothing to
     * leak, because transaction() only ever issues it in a transaction of our
     * own. MySQL's innodb_lock_wait_timeout is session-scoped with no
     * transactional equivalent, so the previous value is captured into a user
     * variable and put back afterwards. Under plain FPM the connection dies with
     * the request and the restore is redundant; under Octane, a persistent PDO,
     * or a pooled connection it is the difference between bounding one query and
     * silently retuning someone's database session for every later one.
     *
     * The restore reads the saved value through IFNULL. User variables are
     * per-session, so a connection that died inside the transaction and was
     * transparently reconnected has none: the bare variable would be NULL, which
     * innodb_lock_wait_timeout rejects, turning a lost connection into a
     * confusing SET error. Falling back to the server's own global value puts
     * the fresh session back where it already was.
     *
     * The interval is interpolated rather than bound: SET accepts no parameter
     * placeholders on either server, and the value is an int cast from config.
     *
     * @return array{before: list<string>, inside: list<string>, after: list<string>}
     */
    public function statements(string $driver, int $seconds): array
    {
        $none = ['before' => [], 'inside' => [], 'after' => []];

        if ($seconds <= 0) {
            return $none;
        }

        return match ($driver) {
            'pgsql' => [
                'before' => [],
                'inside' => ["set local lock_timeout = '{$seconds}s'"],
                'after' => [],
            ],
            'mysql', 'mariadb' => [
                'before' => [
                    'set @laravel_conditional_requests_lock_timeout = @@session.innodb_lock_wait_timeout',
                    "set session innodb_lock_wait_timeout = {$seconds}",
                ],
                'inside' => [],
                'after' => [
                    'set session innodb_lock_wait_timeout = ifnull(@laravel_conditional_requests_lock_timeout, @@global.innodb_lock_wait_timeout)',
                ],
            ],
            default => $none,
        };
    }

    /**
     * Whether this failure was the lock, rather than the query.
     *
     * The framework's own DetectsConcurrencyErrors covers deadlocks and
     * serialization failures across the drivers Laravel supports, including
     * MySQL's "Lock wait timeout exceeded" — and defers to a detector bound in
     * the container where an application has bound one of its own. It does not
     * cover PostgreSQL's lock_timeout, which raises
     * SQLSTATE 55P03 with a message the detector's list does not carry, nor SQL
     * Server's 1222 — both are checked here.
     *
     * A deadlock is folded in deliberately: from the client's side being the
     * deadlock victim and timing out waiting are the same event with the same
     * remedy.
     */
    public function caused(Throwable $exception): bool
    {
        if ($this->causedByConcurrencyError($exception)) {
            return true;
        }

        if ($exception->getCode() === '55P03') {
            return true;
        }

        return Str::contains($exception->getMessage(), [
            'canceling statement due to lock timeout',
            'Lock request time out period exceeded',
        ]);
    }
}
