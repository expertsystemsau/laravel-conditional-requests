<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Preconditions\PreconditionEvaluator;
use ExpertSystems\ConditionalRequests\Preconditions\PreconditionOutcome;
use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Http\Request;

/**
 * An unsafe request carrying the given headers — the only input the evaluator
 * takes besides the current validator, built by hand so these stay unit tests
 * with no kernel, no router, and no database in them.
 *
 * @param  array<string, string>  $headers
 */
function guardedRequest(array $headers = []): Request
{
    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return Request::create('/articles/1', 'PUT', server: $server);
}

// --- strong comparison, RFC 9110 §13.1.1 ---

it('matches a strong tag octet for octet', function (): void {
    expect((new PreconditionEvaluator)->matchesStrongly('"abc"', new Validator('abc')))->toBeTrue();
});

it('does not match a different tag', function (): void {
    expect((new PreconditionEvaluator)->matchesStrongly('"abc"', new Validator('xyz')))->toBeFalse();
});

it('matches any entry in a comma separated list', function (): void {
    expect((new PreconditionEvaluator)->matchesStrongly('"one", "two", "three"', new Validator('two')))->toBeTrue();
});

it('tolerates arbitrary whitespace around list entries', function (): void {
    expect((new PreconditionEvaluator)->matchesStrongly("  \"one\" ,\t\"two\"  ,  ", new Validator('two')))->toBeTrue();
});

it('rejects a weak entry under strong comparison', function (): void {
    // §13.1.1: If-Match uses strong comparison, so a weak entry can never
    // satisfy it. werk365 strips the W/ prefix by default — defect #4.
    expect((new PreconditionEvaluator)->matchesStrongly('W/"abc"', new Validator('abc')))->toBeFalse();
});

it('rejects every entry when the current validator is weak', function (): void {
    expect((new PreconditionEvaluator)->matchesStrongly('"abc"', new Validator('abc', weak: true)))->toBeFalse();
});

it('does not match an unquoted entry', function (): void {
    // entity-tag = [ weak ] opaque-tag; opaque-tag = DQUOTE *etagc DQUOTE.
    expect((new PreconditionEvaluator)->matchesStrongly('abc', new Validator('abc')))->toBeFalse();
});

it('does not match when there is no current validator', function (): void {
    expect((new PreconditionEvaluator)->matchesStrongly('"abc"', null))->toBeFalse();
});

it('ignores empty entries in a list', function (): void {
    expect((new PreconditionEvaluator)->matchesStrongly(',,"abc",,', new Validator('abc')))->toBeTrue();
});

// --- weak comparison, RFC 9110 §13.1.2 ---

it('matches a weak entry against a strong validator under weak comparison', function (): void {
    expect((new PreconditionEvaluator)->matchesWeakly('W/"abc"', new Validator('abc')))->toBeTrue();
});

it('matches a strong entry against a weak validator under weak comparison', function (): void {
    expect((new PreconditionEvaluator)->matchesWeakly('"abc"', new Validator('abc', weak: true)))->toBeTrue();
});

it('still requires the opaque tags to be identical under weak comparison', function (): void {
    expect((new PreconditionEvaluator)->matchesWeakly('W/"abc"', new Validator('xyz')))->toBeFalse();
});

// --- If-Match, through evaluate() ---

it('passes an If-Match naming the current version', function (): void {
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-Match' => '"abc"']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Passed);
});

it('fails an If-Match naming a stale version', function (): void {
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-Match' => '"stale"']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Failed);
});

it('fails an If-Match when there is no current validator', function (): void {
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-Match' => '"abc"']), null, false))
        ->toBe(PreconditionOutcome::Failed);
});

it('passes a bare If-Match wildcard when the resource exists', function (): void {
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-Match' => '*']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Passed);
});

it('fails a bare If-Match wildcard when the resource does not exist', function (): void {
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-Match' => '*']), null, false))
        ->toBe(PreconditionOutcome::Failed);
});

it('tolerates whitespace around a bare wildcard', function (): void {
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-Match' => '  *  ']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Passed);
});

it('treats a quoted asterisk as an entity tag rather than a wildcard', function (): void {
    // werk365 looks for a quoted "*" and so misses the real wildcard entirely
    // — defect #3. Here "*" is just a tag whose opaque value is an asterisk.
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-Match' => '"*"']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Failed);
});

it('matches a quoted asterisk against a resource whose tag really is an asterisk', function (): void {
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-Match' => '"*"']), new Validator('*'), false))
        ->toBe(PreconditionOutcome::Passed);
});

it('fails a malformed If-Match rather than passing it', function (): void {
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-Match' => 'not a valid tag']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Failed);
});

it('compares If-Match strongly through evaluate', function (): void {
    // §13.1.1 is the rule this phase exists for, pinned at the level the
    // middleware actually calls. Every other If-Match case in this file uses
    // inputs where strong and weak comparison agree, so swapping
    // matchesStrongly() for matchesWeakly() inside evaluate() left them all
    // green. This one goes red.
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-Match' => 'W/"abc"']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Failed);
});

// --- If-None-Match on an unsafe method ---

it('fails an If-None-Match wildcard when the resource already exists', function (): void {
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-None-Match' => '*']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Failed);
});

it('passes an If-None-Match wildcard when the resource does not exist', function (): void {
    // Amended with the v0.3 write-path sweep: the create guard now turns on the
    // strategy's own answer to "is the target there", not on a null validator.
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-None-Match' => '*']), null, false, exists: false))
        ->toBe(PreconditionOutcome::Passed);
});

it('fails an If-None-Match naming the current version', function (): void {
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-None-Match' => '"abc"']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Failed);
});

it('passes an If-None-Match naming some other version', function (): void {
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-None-Match' => '"other"']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Passed);
});

it('compares If-None-Match weakly', function (): void {
    // §13.1.2 permits weak comparison here, unlike If-Match.
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-None-Match' => 'W/"abc"']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Failed);
});

// --- the weak-prefixed wildcard ---

it('treats a weak prefixed wildcard as the wildcard on the create guard', function (): void {
    // The prefix comes off before the wildcard test, which is Symfony's order
    // (Response.php:1135) and the order v0.2's read-path guard was corrected
    // to. Testing the raw token would call W/* a concrete tag, find it matches
    // nothing, and let a create through the one guard that exists to stop it.
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-None-Match' => 'W/*']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Failed);
});

it('passes a weak prefixed wildcard create guard when the resource does not exist', function (): void {
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-None-Match' => 'W/*']), null, false, exists: false))
        ->toBe(PreconditionOutcome::Passed);
});

it('does not treat a weak prefixed wildcard as the wildcard on If-Match', function (): void {
    // Amended with the v0.3 write-path sweep. This previously asserted Passed,
    // on the reasoning that one rule for both headers is the rule people
    // remember. But the wildcard is a grammar alternative rather than an entity
    // tag, so `W/*` is malformed rather than weak — and §13.1.1's strong
    // comparison makes the fail-closed reading the right one here, which is
    // also what the README has always promised. The other header keeps
    // Symfony's reading, where stripping first is what closed a 304 oracle.
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-Match' => 'W/*']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Failed);
});

it('fails a weak prefixed If-Match wildcard when the resource does not exist', function (): void {
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-Match' => 'W/*']), null, false))
        ->toBe(PreconditionOutcome::Failed);
});

it('reads the same weak prefixed wildcard differently on each header', function (): void {
    // Both answers are the fail-closed one for the guard that header drives:
    // the update guard refuses a malformed field value, and the create guard
    // refuses to call it a tag that matches nothing.
    $evaluator = new PreconditionEvaluator;

    expect($evaluator->evaluate(guardedRequest(['If-Match' => 'W/*']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Failed)
        ->and($evaluator->evaluate(guardedRequest(['If-None-Match' => 'W/*']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Failed);
});

it('still accepts a bare If-Match wildcard with the weakness rule tightened', function (): void {
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-Match' => '*']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Passed);
});

it('does not treat a wildcard inside a list as the wildcard', function (): void {
    // The grammar is `If-Match = "*" / #entity-tag`: the wildcard is the whole
    // field value or it is nothing. A bare * among tags is a malformed member,
    // ignored per §7, and the surrounding tags still decide the outcome.
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-Match' => '"abc", *']), new Validator('xyz'), false))
        ->toBe(PreconditionOutcome::Failed);
});

// --- precedence and the required flag ---

it('evaluates If-Match first when both headers are present', function (): void {
    // §13.2.2: If-Match is evaluated first and If-None-Match is consulted only
    // in its absence. A satisfied If-Match wins over a wildcard that would fail.
    expect((new PreconditionEvaluator)->evaluate(
        guardedRequest(['If-Match' => '"abc"', 'If-None-Match' => '*']),
        new Validator('abc'),
        false,
    ))->toBe(PreconditionOutcome::Passed);
});

it('passes an unguarded request carrying no precondition', function (): void {
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Passed);
});

it('requires a precondition on a required route that carries none', function (): void {
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(), new Validator('abc'), true))
        ->toBe(PreconditionOutcome::Required);
});

it('refuses a blank If-Match rather than treating it as absent', function (): void {
    // §5.6.1 gives an empty list zero members, so nothing can match and §13.1.1
    // makes the condition false: a present-but-blank If-Match is a precondition
    // that cannot hold, not a missing one. This test previously asserted the
    // opposite, from the task brief; the brief's rule was wrong. On a route
    // without `required` the collapse to "absent" let a client templating
    // `If-Match: ${etag}` with an empty variable clobber the record it had
    // asked to be guarded against.
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-Match' => '   ']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Failed);
});

it('refuses a blank If-Match on a required route too', function (): void {
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-Match' => '   ']), new Validator('abc'), true))
        ->toBe(PreconditionOutcome::Failed);
});

it('answers a blank If-Match and a comma only If-Match the same way', function (): void {
    // Both are "header present, zero valid members". `,` already answered 412;
    // a rule that separates the two is one nobody can predict.
    $evaluator = new PreconditionEvaluator;

    expect($evaluator->evaluate(guardedRequest(['If-Match' => '']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Failed)
        ->and($evaluator->evaluate(guardedRequest(['If-Match' => ',']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Failed);
});

it('still treats a blank If-None-Match as absent', function (): void {
    // The other side is deliberately unchanged. Zero members means nothing
    // matched, which satisfies §13.1.2 either way, so the collapse costs
    // nothing here — and on a required route it is the safer reading: a field
    // value naming no versions is not the precondition that route demands.
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-None-Match' => '   ']), new Validator('abc'), true))
        ->toBe(PreconditionOutcome::Required);
});

it('accepts If-None-Match as a precondition on a required route', function (): void {
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-None-Match' => '*']), null, true, exists: false))
        ->toBe(PreconditionOutcome::Passed);
});

it('refuses a concrete If-None-Match as the precondition a required route demands', function (): void {
    // §13.2.2 has a non-matching If-None-Match proceed, and on a route without
    // `required` it still does. Counting it as the precondition a guarded route
    // demanded is what defeated the flag: the field value states no version the
    // client believes it is writing over, so 428 asks for one that does.
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-None-Match' => '"other"']), new Validator('abc'), true))
        ->toBe(PreconditionOutcome::Required);
});

it('refuses the stale tag If-Match rejects when it is moved to If-None-Match', function (): void {
    // The sharpest form of the bypass: take the tag the update guard correctly
    // refuses and send it on the other header. It has to stay refused.
    $evaluator = new PreconditionEvaluator;

    expect($evaluator->evaluate(guardedRequest(['If-Match' => '"stale"']), new Validator('abc'), true))
        ->toBe(PreconditionOutcome::Failed)
        ->and($evaluator->evaluate(guardedRequest(['If-None-Match' => '"stale"']), new Validator('abc'), true))
        ->toBe(PreconditionOutcome::Required);
});

it('refuses a malformed If-None-Match on a required route rather than passing it', function (): void {
    // Unquoted, so it is not an entity tag and matches nothing — which under
    // §13.1.2 means the condition holds and the write proceeds. On a required
    // route that made every value a client could type into a valid bypass.
    $evaluator = new PreconditionEvaluator;

    foreach (['garbage', ',', 'W/', '**', '"*"', 'W/"0"'] as $value) {
        expect($evaluator->evaluate(guardedRequest(['If-None-Match' => $value]), new Validator('abc'), true))
            ->toBe(PreconditionOutcome::Required);
    }
});

it('still evaluates a concrete If-None-Match normally without required', function (): void {
    // The comparison semantics are untouched: only what counts as having
    // supplied a precondition changed.
    $evaluator = new PreconditionEvaluator;

    expect($evaluator->evaluate(guardedRequest(['If-None-Match' => '"other"']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Passed)
        ->and($evaluator->evaluate(guardedRequest(['If-None-Match' => '"abc"']), new Validator('abc'), false))
        ->toBe(PreconditionOutcome::Failed);
});

// --- the create guard and target existence ---

it('refuses an If-None-Match wildcard when the target exists but yields no validator', function (): void {
    // The record is there and the strategy has nothing to compare. Reading that
    // null as "absent" let the only precondition meant to stop a second writer
    // overwrite the first one's row.
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-None-Match' => '*']), null, false, exists: true))
        ->toBe(PreconditionOutcome::Failed);
});

it('refuses an If-None-Match wildcard when the target existence is unknown', function (): void {
    // Null is "cannot tell" — a guard placed ahead of SubstituteBindings, or
    // kernel-global placement. It fails closed, because guessing "absent" is
    // what overwrote live records.
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-None-Match' => '*']), null, false, exists: null))
        ->toBe(PreconditionOutcome::Failed);
});

it('defaults an unknown target existence to a refusal', function (): void {
    // The parameter's default is the fail-closed answer, so a caller that has
    // not been taught to supply it cannot reopen the hole by omission.
    expect((new PreconditionEvaluator)->evaluate(guardedRequest(['If-None-Match' => '*']), null, false))
        ->toBe(PreconditionOutcome::Failed);
});

it('still refuses an If-None-Match wildcard against a validator whatever existence says', function (): void {
    // Both inputs have to agree before a create proceeds; a validator in hand
    // is a resource that exists, whatever a strategy claims.
    $evaluator = new PreconditionEvaluator;

    foreach ([true, false, null] as $exists) {
        expect($evaluator->evaluate(guardedRequest(['If-None-Match' => '*']), new Validator('abc'), false, $exists))
            ->toBe(PreconditionOutcome::Failed);
    }
});

it('leaves the If-Match wildcard turning on the validator alone', function (): void {
    // §13.1.1's update guard is already fail-closed on a null validator and is
    // deliberately not rewired: a resource that cannot state a version cannot
    // satisfy an If-Match, whatever its existence.
    $evaluator = new PreconditionEvaluator;

    expect($evaluator->evaluate(guardedRequest(['If-Match' => '*']), null, false, exists: true))
        ->toBe(PreconditionOutcome::Failed)
        ->and($evaluator->evaluate(guardedRequest(['If-Match' => '*']), new Validator('abc'), false, exists: null))
        ->toBe(PreconditionOutcome::Passed);
});
