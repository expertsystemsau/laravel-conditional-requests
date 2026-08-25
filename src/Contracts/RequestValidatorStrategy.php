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
 * fromRequest() answering suppresses the middleware's streamed/binary/
 * size-ceiling checks for that response: those checks exist only to avoid
 * reading a body, and a validator already in hand cost no body read. The
 * suppression follows the answer, not the interface — return null and
 * fromResponse() is asked the ordinary way, under every one of those rules. A
 * strategy is free to answer early when it can and hash the body when it
 * cannot; the cost of the second path is simply that an oversized, streamed, or
 * binary response goes untagged, exactly as it would under `body`.
 *
 * A route whose validator can answer before the controller runs skips
 * everything the controller and any later middleware would otherwise decide
 * before a 304 is sent — per-record authorization, signed URLs, subscription
 * and feature gates. Place `conditional` after any middleware that can reject
 * the request, the same way the `model` strategy requires (see the README).
 */
interface RequestValidatorStrategy extends ValidatorStrategy
{
    /**
     * Produce a validator from the request alone, or null when it cannot.
     */
    public function fromRequest(Request $request): ?Validator;
}
