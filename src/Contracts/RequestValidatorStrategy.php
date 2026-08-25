<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Contracts;

use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Http\Request;

/**
 * A strategy that can produce a validator before the controller runs.
 *
 * This is what makes the pre-controller 304 short-circuit possible: the
 * middleware asks for a validator from the request alone, and a matching
 * If-None-Match is answered without ever invoking the route action. A strategy
 * that only implements ValidatorStrategy is simply never asked.
 */
interface RequestValidatorStrategy extends ValidatorStrategy
{
    /**
     * Produce a validator from the request alone, or null when it cannot.
     */
    public function fromRequest(Request $request): ?Validator;
}
