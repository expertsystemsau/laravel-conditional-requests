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

    /**
     * Whether the resource this request addresses exists, when that can be
     * determined from the request alone.
     *
     * Three answers, and the third is the point of the method:
     *
     *  - true — the resource is there.
     *  - false — the resource is definitely not there.
     *  - null — this strategy cannot tell.
     *
     * fromRequest() returning null collapses those three into one. A record
     * that exists but yields no validator, a record that is genuinely absent,
     * and a request nothing has been routed for are indistinguishable from a
     * null validator, and `If-None-Match: *` — the create guard, whose entire
     * job is refusing to write over a resource that is already there — used to
     * read that one null as "absent" and pass. A live record was then silently
     * overwritten by the only precondition meant to stop it.
     *
     * The create guard now writes only on a definite false. That makes null
     * fail closed with 412, so answer it only when the existence of the target
     * genuinely cannot be established: a strategy that guesses `false` here
     * reopens the hole this method exists to close.
     *
     * Only the write path asks. Nothing on the read path calls it, and a
     * strategy is free to answer without touching storage — the question is
     * about the request's target, not about a query.
     */
    public function targetExists(Request $request): ?bool;
}
