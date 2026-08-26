<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Validators;

use ExpertSystems\ConditionalRequests\Contracts\ProvidesConditionalValidator;
use ExpertSystems\ConditionalRequests\Contracts\RequestValidatorStrategy;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
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
final readonly class ModelStrategy implements RequestValidatorStrategy
{
    public function __construct(private bool $weak = false) {}

    public function fromRequest(Request $request): ?Validator
    {
        $model = $this->target($request);

        if (! $model instanceof ProvidesConditionalValidator) {
            return null;
        }

        return $this->weaken($model->conditionalValidator($request));
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
     */
    public function targetExists(Request $request): ?bool
    {
        $route = $request->route();

        // Kernel-global placement, where nothing has been routed yet.
        if (! $route instanceof Route || ! $route->hasParameters()) {
            return null;
        }

        $unsubstituted = false;

        foreach ($route->parameters() as $parameter) {
            if ($parameter instanceof ProvidesConditionalValidator) {
                return true;
            }

            $unsubstituted = $unsubstituted || is_string($parameter);
        }

        return $unsubstituted ? null : false;
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
     * Model validators are strong by default (design §4). The `weak` config key
     * opts a deployment out of the guarantee on read-only routes; it can only
     * ever weaken a tag, never strengthen one the model marked weak itself.
     */
    private function weaken(?Validator $validator): ?Validator
    {
        if (! $validator instanceof Validator || ! $this->weak || $validator->weak) {
            return $validator;
        }

        return new Validator($validator->etag, weak: true);
    }
}
