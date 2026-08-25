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
 *
 * Implementing this interface is a claim about the strategy, not just about
 * fromRequest(): the middleware reads it as permission to skip its
 * streamed/binary/size-ceiling checks on fromResponse() as well, since those
 * checks exist only to avoid reading a body a request-derived strategy should
 * never need. fromResponse() must honour that claim — never fall back to
 * hashing the rendered body, a stream, or a binary file — or a response the
 * ceiling was meant to protect gets read anyway on the fallback path.
 *
 * A route whose validator can answer before the controller runs also skips
 * everything the controller would otherwise decide before a 304 is sent,
 * per-record authorization included. Place `conditional` after authorization
 * middleware on any route using such a strategy, the same way the `model`
 * strategy requires (see the README).
 */
interface RequestValidatorStrategy extends ValidatorStrategy
{
    /**
     * Produce a validator from the request alone, or null when it cannot.
     */
    public function fromRequest(Request $request): ?Validator;
}
