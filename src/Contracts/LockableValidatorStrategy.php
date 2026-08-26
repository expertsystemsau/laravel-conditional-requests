<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * A strategy whose target is a database row, so `lock` mode can serialise on it.
 *
 * RequestValidatorStrategy answers "what version is this?" with an opaque
 * string, which is all the read path and the unlocked write path ever need.
 * Locking needs the thing behind the string: a row on a known connection that
 * can be re-read under SELECT … FOR UPDATE. This contract is that extra
 * knowledge, kept separate so that a strategy which cannot supply it stays a
 * perfectly good strategy everywhere except under `lock`.
 *
 * The two methods are separate because the middleware needs the connection —
 * which only the model knows — before it can open the transaction the lock has
 * to live inside. lockTarget() runs first, outside the transaction; the
 * middleware opens a transaction on the returned model's connection; then
 * lockAndRefresh() runs inside it.
 */
interface LockableValidatorStrategy extends RequestValidatorStrategy
{
    /**
     * The record this request is about to write, before any lock is taken.
     *
     * Null means there is no row to lock. That is not an error on its own — a
     * create has no row yet — so the middleware distinguishes the two cases by
     * whether fromRequest() produced a validator: a resource that exists but
     * cannot be pointed at is a configuration error, a resource that does not
     * exist yet is an ordinary create.
     */
    public function lockTarget(Request $request): ?Model;

    /**
     * Re-read the target under a row lock and make it the record fromRequest()
     * will answer from.
     *
     * Called inside the transaction the middleware opened on $target's own
     * connection, and immediately before the precondition is evaluated for the
     * second time. Rebinding is part of the contract rather than something the
     * middleware does afterwards: only the implementation knows where its
     * target came from, and a re-read that is not visible to fromRequest()
     * leaves the second evaluation reading the same stale record as the first,
     * which is the whole failure this mode exists to prevent.
     *
     * Returns null when the row is gone, in which case the target must no
     * longer be visible to fromRequest() either.
     */
    public function lockAndRefresh(Request $request, Model $target): ?Model;
}
