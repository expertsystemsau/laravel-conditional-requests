<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Preconditions;

/**
 * What the evaluator decided about an unsafe request.
 *
 * Three states rather than a boolean, because "no precondition was sent" and
 * "the precondition that was sent does not hold" are different failures with
 * different answers — 428 tells a client to send one, 412 tells it the one it
 * sent is stale. Collapsing them is defect #2 in werk365/etagconditionals,
 * where an absent If-Match simply returns early and the guard becomes opt-out.
 *
 * @internal The public surface is the HTTP behaviour it names — 412 and 428 —
 *           documented in docs/writes.md.
 */
enum PreconditionOutcome
{
    /**
     * The precondition holds, or there was none to hold. Run the controller.
     */
    case Passed;

    /**
     * A precondition was sent and does not hold — 412 Precondition Failed.
     */
    case Failed;

    /**
     * No precondition on a route that demands one — 428 Precondition Required.
     */
    case Required;
}
