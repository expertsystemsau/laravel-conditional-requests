<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Preconditions;

use DateTimeImmutable;
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
 *  - §13.1.4, If-Unmodified-Since, compares dates at one-second granularity.
 *    Both sides are whole seconds — Validator floors its own — and equality
 *    satisfies the condition, because a client echoing back the date we
 *    published is the normal case rather than an edge one.
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
     *
     * What satisfies `required` is narrower than what this method evaluates.
     * Two field values state a version the client believes it is writing over:
     * an If-Match, whatever it names, and the If-None-Match wildcard, which
     * says "only if nothing is there". A *concrete* If-None-Match says neither.
     * §13.2.2 has a non-matching one proceed, and on a route that never asked
     * for a guard it still does — but treating it as the precondition a
     * `required` route demands defeated the flag outright: the stale tag
     * If-Match correctly refuses passes verbatim once it is moved to the other
     * header, as does any value at all, `"0"` and `garbage` included. On a
     * `required` route it is answered 428, asking for the precondition the
     * route actually wants. The comparison itself is untouched; what changed is
     * only what counts as having supplied one.
     *
     * Both wildcards fail CLOSED, and they read different inputs to do it.
     *
     *  - `If-Match: *` turns on $current. Nothing can be shown to exist, so
     *    the update guard refuses with 412 and no write happens.
     *  - `If-None-Match: *` turns on $exists, which the strategy answers
     *    separately: true, false, or null for "cannot tell". The create guard
     *    passes only on a definite false.
     *
     * The second used to turn on $current too, and that was the hole. A null
     * validator collapses three states — the resource is absent, it exists but
     * yields no validator, and nothing has been routed — and reading the
     * collapsed null as "absent" let the create guard write over a live
     * record: the lost update this class exists to refuse, arriving through
     * the one precondition meant to stop it. $exists keeps the states apart,
     * so "cannot tell" is now 412 rather than a write.
     *
     * @param  bool|null  $exists  whether the target resource is there, or null
     *                             when the strategy cannot tell
     */
    public function evaluate(Request $request, ?Validator $current, bool $required, ?bool $exists = null): PreconditionOutcome
    {
        $ifMatch = $this->sentHeader($request, 'If-Match');

        if ($ifMatch !== null) {
            return $this->outcome($this->isWildcard($ifMatch)
                ? $current instanceof Validator
                : $this->matchesStrongly($ifMatch, $current));
        }

        // §13.2.2 step 2: consulted only when If-Match is absent, and evaluated
        // BEFORE If-None-Match rather than after it. A field value that is not
        // a valid HTTP-date is ignored per §13.1.4, which unmodifiedSince()
        // reports as null — the request then carries no date precondition at
        // all and falls through to the branches below.
        $ifUnmodifiedSince = $this->header($request, 'If-Unmodified-Since');

        if ($ifUnmodifiedSince !== null) {
            $satisfied = $this->unmodifiedSince($ifUnmodifiedSince, $current);

            if ($satisfied !== null) {
                return $this->outcome($satisfied);
            }
        }

        $ifNoneMatch = $this->header($request, 'If-None-Match');

        if ($ifNoneMatch !== null) {
            if ($this->isWeakComparisonWildcard($ifNoneMatch)) {
                return $this->outcome(! $current instanceof Validator && $exists === false);
            }

            return $required
                ? PreconditionOutcome::Required
                : $this->outcome(! $this->matchesWeakly($ifNoneMatch, $current));
        }

        return $required ? PreconditionOutcome::Required : PreconditionOutcome::Passed;
    }

    /**
     * Whether the client supplied a precondition this class would evaluate.
     *
     * The two field values are read exactly as evaluate() reads them, so the
     * answer cannot drift from what evaluation would have done with them: an
     * If-Match counts the moment the header is present, blank included, and an
     * If-None-Match counts only when it names something.
     *
     * The write path asks this when it has established that it cannot evaluate
     * a precondition at all — no strategy can produce the current validator
     * before the controller runs. A client that sent one asked for a guarantee
     * that cannot be given, and is refused rather than quietly written.
     */
    public function supplied(Request $request): bool
    {
        return $this->sentIfMatch($request)
            || $this->header($request, 'If-None-Match') !== null;
    }

    /**
     * Whether the client sent an If-Match at all.
     *
     * The write path asks so it can raise a configuration error before
     * comparing an If-Match against a validator that cannot satisfy one. Blank
     * counts, exactly as it does in evaluate(): the header is present, and the
     * comparison it is about to lose is the one §13.1.1 governs either way.
     */
    public function sentIfMatch(Request $request): bool
    {
        return $this->sentHeader($request, 'If-Match') !== null;
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
     * Whether an If-Unmodified-Since field value is satisfied by the current
     * validator, per RFC 9110 §13.1.4. Null means the value is not a valid
     * HTTP-date and must be ignored — "no opinion", not "satisfied".
     *
     * Two rules do the work here. The comparison accepts equality, because the
     * normal case is a client echoing back the date this package published;
     * refusing equality would make the header useless for its own purpose. And
     * the date on our side is already floored to the whole second by Validator,
     * because comparing a raw 12:00:00.700 against the 12:00:00 we published
     * would refuse that client's write forever.
     *
     * A resource with no date at all fails closed. The condition cannot be
     * shown to hold, and a client that sent this header asked to be refused
     * when the server cannot vouch for the state — proceeding would silently
     * turn the guard it asked for into a no-op.
     */
    public function unmodifiedSince(string $header, ?Validator $current): ?bool
    {
        $since = $this->httpDate($header);

        if ($since === null) {
            return null;
        }

        $modified = $current?->lastModified;

        if (! $modified instanceof DateTimeImmutable) {
            return false;
        }

        return $modified->getTimestamp() <= $since;
    }

    /**
     * An HTTP-date as a Unix timestamp, or null when it is not one.
     *
     * strtotime() is what Symfony's Response::isNotModified() uses for
     * If-Modified-Since, so both halves of the family accept exactly the same
     * field values — including all three formats §5.6.7 requires a recipient to
     * read. HeaderBag::getDate() would be the tidier call and is unusable: it
     * throws RuntimeException on a value it cannot parse, and §7 wants a
     * malformed validator header ignored rather than fatal.
     *
     * The blank check is load-bearing rather than defensive. strtotime('   ')
     * returns the *current time* instead of false, so a whitespace-only field
     * value reaching the parser would be read as "unmodified since now" and
     * satisfy every precondition it was asked about. evaluate() already treats
     * a blank header as absent; this makes the parser safe on its own terms.
     */
    private function httpDate(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * A header's value as the client sent it, or null only when the client did
     * not send the header at all.
     *
     * `If-Match = "*" / #entity-tag`, and §5.6.1 gives an empty list zero
     * members, so a present-but-blank field value is a precondition naming no
     * versions rather than no precondition. Nothing can match zero members,
     * §13.1.1 makes the condition false, and the answer is 412. Collapsing it
     * to "absent" instead is what let `If-Match:` with an empty value — a
     * client templating an etag variable that came out empty — sail past the
     * guard it had asked for and clobber the record. `If-Match: ,` is the same
     * state spelled differently and already answered 412; the two must not
     * disagree.
     */
    private function sentHeader(Request $request, string $name): ?string
    {
        if (! $request->headers->has($name)) {
            return null;
        }

        return (string) $request->headers->get($name);
    }

    /**
     * A header's value, or null when it is absent or blank.
     *
     * If-None-Match alone reads its field value this way. Zero members there
     * means nothing matched, which satisfies §13.1.2 either way, so the
     * collapse costs nothing on an unguarded route and is the safer reading on
     * a `required` one: a field value naming no versions is not the
     * precondition that route demands, and 428 asks for a real one. It is also
     * what Symfony's Request::getETags() effectively does on the read path.
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
     * Whether the entire field value is the wildcard, read exactly as the
     * grammar writes it.
     *
     * `If-Match = "*" / #entity-tag`: the wildcard is unquoted, unprefixed, and
     * it is the whole field value rather than a member of the tag list.
     * werk365/etagconditionals looks for a quoted `"*"` inside the list, so a
     * spec-compliant `If-Match: *` gets a spurious 412 there — defect #3. Here
     * `"*"` is an entity tag whose opaque value happens to be an asterisk, and
     * it is compared as one.
     *
     * `W/*` is not this. The wildcard is a grammar alternative rather than an
     * entity tag, so there is nothing for a weakness prefix to attach to and
     * `W/*` is simply malformed. §13.1.1 asks for strong comparison, and the
     * fail-closed reading of a malformed field value on the update guard is
     * 412 — which is also what the README has always promised: a `W/`-prefixed
     * token never satisfies If-Match. The other header reads it the other way,
     * and deliberately: see isWeakComparisonWildcard().
     */
    private function isWildcard(string $header): bool
    {
        return trim($header) === '*';
    }

    /**
     * Whether the field value is the wildcard under §13.1.2's weak comparison,
     * where the weakness prefix comes off before the test rather than after it.
     *
     * That order is Symfony's (Response.php:1135) and it is the correction
     * v0.2's read-path guard had to make. Nothing is a wildcard under the
     * grammar but a bare `*`, yet Symfony drops the prefix first and then
     * matches on the `*` left behind — so on this header, whatever Symfony will
     * call a wildcard has to be one here too, or the two disagree about what
     * matched. On the create guard the same reading is the fail-closed one: a
     * `W/*` treated as a concrete tag matches nothing, and a create guard that
     * matches nothing lets a second create through the only thing stopping it.
     *
     * If-Match cannot follow: there the strict reading is the fail-closed one,
     * and §13.1.1's strong comparison forbids a weak token besides. The two
     * headers differ because the safe answer differs, not by oversight.
     */
    private function isWeakComparisonWildcard(string $header): bool
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
