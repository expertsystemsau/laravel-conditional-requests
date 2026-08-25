<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Preconditions;

use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Http\Request;

/**
 * Evaluates the RFC 9110 §13 preconditions that guard an unsafe request.
 *
 * Symfony owns the read path's comparison through Response::isNotModified(),
 * but nothing in HttpFoundation reads If-Match at all — Request::getETags()
 * (Request.php:1627) reads If-None-Match exclusively and compares it weakly,
 * which is the right rule for a read and the wrong one for a write. This class
 * is the half of §13 the framework does not have.
 *
 * The two comparisons are deliberately different:
 *
 *  - §13.1.1, If-Match, uses STRONG comparison. Both validators must be strong
 *    and their opaque tags octet-identical, so a weak validator on either side
 *    can never satisfy it.
 *  - §13.1.2, If-None-Match, uses WEAK comparison. The opaque tags must be
 *    octet-identical; either side may be weak.
 *
 * Nothing here touches the container, the configuration, or a response, so the
 * whole rule set is exercised by unit tests with a hand-built Request.
 */
final readonly class PreconditionEvaluator
{
    /**
     * Decide what should happen to an unsafe request.
     *
     * Precedence follows §13.2.2: If-Match is evaluated first, and If-None-Match
     * is consulted only in its absence. The `required` flag is what turns the
     * absence of any precondition from "proceed" into 428 — without it the
     * guard is opt-out and a client clobbers freely by omitting a header.
     */
    public function evaluate(Request $request, ?Validator $current, bool $required): PreconditionOutcome
    {
        $ifMatch = $this->header($request, 'If-Match');

        if ($ifMatch !== null) {
            return $this->outcome($this->isWildcard($ifMatch)
                ? $current instanceof Validator
                : $this->matchesStrongly($ifMatch, $current));
        }

        $ifNoneMatch = $this->header($request, 'If-None-Match');

        if ($ifNoneMatch !== null) {
            return $this->outcome($this->isWildcard($ifNoneMatch)
                ? ! $current instanceof Validator
                : ! $this->matchesWeakly($ifNoneMatch, $current));
        }

        return $required ? PreconditionOutcome::Required : PreconditionOutcome::Passed;
    }

    /**
     * Strong comparison of a field value against the current validator, per
     * RFC 9110 §13.1.1.
     */
    public function matchesStrongly(string $header, ?Validator $current): bool
    {
        // A weak validator asserts semantic equivalence, not byte equality, so
        // it cannot satisfy a precondition that requires strong comparison. It
        // is not configurable away: making it so is defect #4 in the incumbent.
        if (! $current instanceof Validator || $current->weak) {
            return false;
        }

        foreach ($this->tags($header) as $tag) {
            if (str_starts_with($tag, 'W/')) {
                continue;
            }

            if ($this->opaque($tag) === $current->etag) {
                return true;
            }
        }

        return false;
    }

    /**
     * Weak comparison of a field value against the current validator, per
     * RFC 9110 §13.1.2. Either side may be weak; the opaque tags must match.
     */
    public function matchesWeakly(string $header, ?Validator $current): bool
    {
        if (! $current instanceof Validator) {
            return false;
        }

        foreach ($this->tags($header) as $tag) {
            if ($this->opaque($tag) === $current->etag) {
                return true;
            }
        }

        return false;
    }

    /**
     * A header's value, or null when it is absent or blank.
     *
     * A present-but-blank header carries no precondition, so it is treated as
     * absent rather than as a precondition that cannot hold.
     */
    private function header(Request $request, string $name): ?string
    {
        $value = $request->headers->get($name);

        if ($value === null || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * Whether the entire field value is the wildcard.
     *
     * The grammar is `If-Match = "*" / #entity-tag`: the wildcard is unquoted
     * and it is the whole field value, not a member of the tag list.
     * werk365/etagconditionals looks for a quoted `"*"` inside the list, so a
     * spec-compliant `If-Match: *` gets a spurious 412 there — defect #3. Here
     * `"*"` is an entity tag whose opaque value happens to be an asterisk, and
     * it is compared as one.
     *
     * The weakness prefix comes off before the test rather than after it. That
     * order is Symfony's (Response.php:1135) and it is the correction v0.2's
     * read-path guard had to make: nothing is a wildcard under the grammar but
     * a bare `*`, yet Symfony drops the prefix first and then matches on the
     * `*` left behind, so testing the raw token calls `W/*` a concrete tag.
     * On the create guard that reading is the dangerous one — a `W/*` that
     * matches nothing lets a second create through the only thing stopping it —
     * and a rule that holds for one header and not the other is a rule nobody
     * remembers, so both headers strip first.
     */
    private function isWildcard(string $header): bool
    {
        return $this->withoutWeakness(trim($header)) === '*';
    }

    /**
     * Split a field value into its entries, discarding optional whitespace and
     * empty members.
     *
     * @return list<string>
     */
    private function tags(string $header): array
    {
        $tags = [];

        foreach (explode(',', $header) as $tag) {
            $tag = trim($tag);

            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * The opaque tag inside an entity tag, or null when the entry is malformed.
     *
     * `entity-tag = [ weak ] opaque-tag` and `opaque-tag = DQUOTE *etagc
     * DQUOTE`, so a bare unquoted token is not an entity tag and matches
     * nothing. §7 wants malformed headers ignored rather than fatal, and
     * "matches nothing" is what that means here: If-Match then fails closed
     * with 412, If-None-Match fails open and the request proceeds.
     */
    private function opaque(string $tag): ?string
    {
        $tag = $this->withoutWeakness($tag);

        if (strlen($tag) < 2 || ! str_starts_with($tag, '"') || ! str_ends_with($tag, '"')) {
            return null;
        }

        return substr($tag, 1, -1);
    }

    /**
     * A token with its weakness prefix dropped, which is all RFC 9110 §8.8.3.2's
     * weak comparison asks for.
     */
    private function withoutWeakness(string $tag): string
    {
        return str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag;
    }

    private function outcome(bool $passed): PreconditionOutcome
    {
        return $passed ? PreconditionOutcome::Passed : PreconditionOutcome::Failed;
    }
}
