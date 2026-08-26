<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Validators;

use ExpertSystems\ConditionalRequests\Contracts\LockableValidatorStrategy;
use ExpertSystems\ConditionalRequests\Contracts\ProvidesConditionalValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Derives a validator from a route-bound record's own version.
 *
 * The validator is known before the controller runs, so a matching
 * If-None-Match costs no controller execution and no serialization — this
 * strategy saves compute, not just bandwidth. It does not cost no queries:
 * with implicit route-model binding, the only wiring this package documents,
 * SubstituteBindings issues the binding query before Conditional ever runs,
 * so a short-circuited 304 still costs that one query.
 */
final readonly class ModelStrategy implements LockableValidatorStrategy
{
    /**
     * @param  bool  $weak  emit weak tags, per the `weak` config key
     * @param  bool  $lastModified  publish the record's modification date, per
     *                              the `last_modified` config key
     */
    public function __construct(private bool $weak = false, private bool $lastModified = true) {}

    public function fromRequest(Request $request): ?Validator
    {
        $model = $this->target($request);

        if (! $model instanceof ProvidesConditionalValidator) {
            return null;
        }

        return $this->conform($model->conditionalValidator($request));
    }

    /**
     * Resolve the same record from the same route once the controller has run.
     *
     * Needed whenever the short-circuit did not fire — the client's tag was
     * stale, or nothing had been routed when the middleware first looked — so
     * the response still leaves carrying the current validator.
     */
    public function fromResponse(Request $request, Response $response): ?Validator
    {
        return $this->fromRequest($request);
    }

    /**
     * The bound record, when it is one `lock` mode can serialise on.
     *
     * A ProvidesConditionalValidator that is not an Eloquent model can still
     * describe its own version perfectly well — it simply has no row to lock.
     * Returning null here is what lets the middleware tell that apart from a
     * create, using the validator it already has in hand.
     */
    public function lockTarget(Request $request): ?Model
    {
        $target = $this->target($request);

        return $target instanceof Model ? $target : null;
    }

    /**
     * The locking read itself.
     *
     * Public because it is the single source of truth for the SQL: sqlite's
     * grammar compiles a lock to the empty string, so the only honest way to
     * assert that a FOR UPDATE is actually asked for is to compile this exact
     * query against a grammar that emits one. Also a real extension point —
     * override to take a shared lock, add a scope, or eager-load.
     *
     * newQuery() rather than newQueryWithoutScopes(): the locked read should
     * see the record the way the rest of the application sees it, so a record
     * soft-deleted since it was bound re-reads as null and the precondition
     * fails closed.
     *
     * @return Builder<Model>
     */
    public function lockingQuery(Model $target): Builder
    {
        return $target->newQuery()
            ->whereKey($target->getKey())
            ->lockForUpdate();
    }

    /**
     * Re-read the target under the lock and put the fresh instance where
     * fromRequest() — and the controller — will find it.
     *
     * The route parameter is matched by identity rather than by name, so the
     * same first-match rule target() applies is not spelled out twice and
     * cannot drift between the two.
     */
    public function lockAndRefresh(Request $request, Model $target): ?Model
    {
        $fresh = $this->lockingQuery($target)->first();

        $route = $request->route();

        if (! $route instanceof Route || ! $route->hasParameters()) {
            return $fresh;
        }

        foreach ($route->parameters() as $name => $parameter) {
            if ($parameter !== $target) {
                continue;
            }

            $fresh instanceof Model
                ? $route->setParameter($name, $fresh)
                : $route->forgetParameter($name);
        }

        return $fresh;
    }

    /**
     * Whether the record this route addresses is there.
     *
     * The write path asks so its create guard can tell "absent" from "present
     * but silent" — see RequestValidatorStrategy::targetExists(). A bound
     * parameter implementing the contract is a record that exists whether or
     * not it produces a validator, which is the distinction fromRequest()
     * cannot express and the one `If-None-Match: *` turns on.
     *
     * Everything else is read from what the parameters hold:
     *
     *  - A parameter still holding its raw URI string means the bindings have
     *    not been substituted — `conditional` is declared ahead of
     *    SubstituteBindings — so nothing can be said and the answer is null.
     *    That case used to read as "absent" and let `If-None-Match: *`
     *    overwrite live records on a misordered route.
     *  - Otherwise there is no target record to speak of: a binder that
     *    answered null, or a collection route such as `POST /articles` that
     *    addresses no record at all. Both are a definite false, and a create
     *    against them proceeds.
     *
     * A route binding more than one record that implements the contract has no
     * answer at all, and raises a configuration error rather than picking one.
     * The read path's first-wins rule is documented and harmless there — the
     * tag on `/articles/{article}/comments/{comment}` simply tracks the
     * article. On a write it inverts the guard: the controller on a nested
     * route usually writes the last record, so the client sending the tag of
     * the record it is modifying is refused with 412 and the write lands only
     * when it sends the tag of a record it is not touching. Only the write path
     * asks this question, so the read path keeps its rule untouched.
     *
     * @throws LogicException when the route binds more than one conditional record
     */
    public function targetExists(Request $request): ?bool
    {
        $route = $request->route();

        // Kernel-global placement, where nothing has been routed yet.
        if (! $route instanceof Route || ! $route->hasParameters()) {
            return null;
        }

        /** @var list<string> $candidates */
        $candidates = [];
        $unsubstituted = false;

        foreach ($route->parameters() as $name => $parameter) {
            if ($parameter instanceof ProvidesConditionalValidator) {
                $candidates[] = (string) $name;

                continue;
            }

            $unsubstituted = $unsubstituted || is_string($parameter);
        }

        if (count($candidates) > 1) {
            throw new LogicException(sprintf(
                '[%s %s] binds more than one record implementing %s [%s], so the conditional write guard '
                .'cannot tell which record the write is protecting. The first bound parameter wins, and the '
                .'controller on a nested route usually writes the last — so the client naming the record it '
                .'is modifying is refused with 412 while the one naming a record it is not touching writes. '
                .'Implement the contract only on the record this route represents, or take the conditional '
                .'middleware off this route.',
                $request->getMethod(),
                $route->uri(),
                ProvidesConditionalValidator::class,
                implode(', ', $candidates),
            ));
        }

        return $candidates !== [] ? true : ($unsubstituted ? null : false);
    }

    /**
     * The first route parameter that can speak for its own version.
     *
     * First rather than best: route parameters keep their declaration order, so
     * the outermost resource wins and the target never moves with runtime
     * state. If that parameter declines, so does this strategy.
     */
    private function target(Request $request): ?ProvidesConditionalValidator
    {
        $route = $request->route();

        // Null under kernel-global placement, where nothing has been routed
        // yet; unbound routes make Route::parameters() throw, so ask first.
        if (! $route instanceof Route || ! $route->hasParameters()) {
            return null;
        }

        foreach ($route->parameters() as $parameter) {
            if ($parameter instanceof ProvidesConditionalValidator) {
                return $parameter;
            }
        }

        return null;
    }

    /**
     * Apply this strategy's settings to whatever the model handed back.
     *
     * Model validators are strong and dated by default. The `weak` and
     * `last_modified` config keys opt a deployment out of each guarantee; both
     * can only ever take something away, never add one the model withheld —
     * this strategy cannot strengthen a tag the model marked weak, and it
     * cannot invent a date the model declined to publish.
     *
     * The rebuild is in one place on purpose: it is the only path on which a
     * validator is reconstructed, and reconstructing it a field at a time is
     * how the modification date would get dropped by accident.
     */
    private function conform(?Validator $validator): ?Validator
    {
        if (! $validator instanceof Validator) {
            return null;
        }

        $weak = $this->weak || $validator->weak;
        $lastModified = $this->lastModified ? $validator->lastModified : null;

        if ($weak === $validator->weak && $lastModified === $validator->lastModified) {
            return $validator;
        }

        return new Validator($validator->etag, $weak, $lastModified);
    }
}
