<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Reading;
use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * A record hydrated by hand at a known modification instant, with the clock
 * parked somewhere else. Both halves matter: the rule under test compares the
 * two, so a test that leaves either to the real clock is testing nothing.
 */
function readingModifiedAt(string $modified, string $now): ?Validator
{
    test()->travelTo(Carbon::parse($now, 'UTC'));

    return (new Reading)
        ->forceFill(['id' => 1, 'label' => 'Hello', 'updated_at' => Carbon::parse($modified, 'UTC')])
        ->conditionalValidator(Request::create('/readings/1'));
}

it('derives the modification date from updated_at', function (): void {
    $validator = readingModifiedAt('2026-08-26 12:00:00', '2026-08-26 12:00:05');

    expect($validator?->lastModified?->format('Y-m-d H:i:s'))->toBe('2026-08-26 12:00:00');
});

it('floors a sub second modification date', function (): void {
    $validator = readingModifiedAt('2026-08-26 12:00:00.700000', '2026-08-26 12:00:05');

    expect($validator?->lastModified?->format('Y-m-d H:i:s.u'))->toBe('2026-08-26 12:00:00.000000');
});

it('publishes no date while the second holding the change is still open', function (): void {
    // THE bug this rule exists to prevent. If 12:00:00 were advertised now, a
    // second change at 12:00:00.900 would be invisible to every client that
    // echoes it back — they would be told 304 while holding a stale copy.
    $validator = readingModifiedAt('2026-08-26 12:00:00.700000', '2026-08-26 12:00:00.900000');

    expect($validator?->lastModified)->toBeNull();
});

it('publishes no date at the last microsecond of that second', function (): void {
    $validator = readingModifiedAt('2026-08-26 12:00:00.000000', '2026-08-26 12:00:00.999999');

    expect($validator?->lastModified)->toBeNull();
});

it('publishes the date the moment that second closes', function (): void {
    $validator = readingModifiedAt('2026-08-26 12:00:00.700000', '2026-08-26 12:00:01.000000');

    expect($validator?->lastModified?->format('Y-m-d H:i:s'))->toBe('2026-08-26 12:00:00');
});

it('publishes no date for a record modified in the future', function (): void {
    // Replica clock skew, or an application writing a date ahead of the server.
    // RFC 9110 §8.8.2.2 forbids a Last-Modified later than the response's own
    // Date, and clamping it to now would publish a time that is not the
    // record's — and would land in the current second anyway.
    $validator = readingModifiedAt('2026-08-26 12:00:30', '2026-08-26 12:00:00');

    expect($validator?->lastModified)->toBeNull();
});

it('keeps the entity tag while the date is suppressed', function (): void {
    // The division of labour: the tag is derived from the raw updated_at at
    // whatever precision the column stores, so it still separates two changes
    // inside one second — exactly the window in which the date cannot.
    test()->travelTo(Carbon::parse('2026-08-26 12:00:00.900000', 'UTC'));

    $request = Request::create('/readings/1');

    $first = (new Reading)->forceFill(['id' => 1, 'updated_at' => Carbon::parse('2026-08-26 12:00:00.700000', 'UTC')])
        ->conditionalValidator($request);

    $second = (new Reading)->forceFill(['id' => 1, 'updated_at' => Carbon::parse('2026-08-26 12:00:00.800000', 'UTC')])
        ->conditionalValidator($request);

    expect($first?->lastModified)->toBeNull()
        ->and($second?->lastModified)->toBeNull()
        ->and($first?->etag)->not->toBe($second?->etag);
});

it('publishes no date for a model that keeps no timestamps', function (): void {
    test()->travelTo(Carbon::parse('2026-08-26 12:00:05', 'UTC'));

    $article = (new Article)->forceFill(['id' => 1, 'version' => 3, 'updated_at' => '2026-08-26 12:00:00']);
    $article->timestamps = false;

    $validator = $article->conditionalValidator(Request::create('/articles/1'));

    expect($validator?->etag)->not->toBeNull()
        ->and($validator?->lastModified)->toBeNull();
});

it('publishes no date when the model names no updated at column', function (): void {
    test()->travelTo(Carbon::parse('2026-08-26 12:00:05', 'UTC'));

    $model = new class extends Article
    {
        protected $table = 'articles';

        const UPDATED_AT = null;
    };

    $validator = $model->forceFill(['id' => 1, 'version' => 3])
        ->conditionalValidator(Request::create('/articles/1'));

    expect($validator?->etag)->not->toBeNull()
        ->and($validator?->lastModified)->toBeNull();
});

it('reads a custom updated at column', function (): void {
    test()->travelTo(Carbon::parse('2026-08-26 12:00:05', 'UTC'));

    $model = new class extends Article
    {
        protected $table = 'articles';

        const UPDATED_AT = 'edited_at';
    };

    $validator = $model->forceFill(['id' => 1, 'edited_at' => '2026-08-26 12:00:00'])
        ->conditionalValidator(Request::create('/articles/1'));

    expect($validator?->lastModified?->format('Y-m-d H:i:s'))->toBe('2026-08-26 12:00:00');
});

it('publishes no date when the column was never loaded', function (): void {
    // A partially selected model — select('id', 'version') — must not trigger
    // a lazy lookup for an attribute that simply is not there.
    test()->travelTo(Carbon::parse('2026-08-26 12:00:05', 'UTC'));

    $validator = (new Article)->setRawAttributes(['id' => 1, 'version' => 3])
        ->conditionalValidator(Request::create('/articles/1'));

    expect($validator?->etag)->not->toBeNull()
        ->and($validator?->lastModified)->toBeNull();
});

it('publishes no date for a stored value it cannot parse', function (): void {
    // A validator is an optimisation. It is never the reason a request fails.
    test()->travelTo(Carbon::parse('2026-08-26 12:00:05', 'UTC'));

    $validator = (new Reading)->setRawAttributes(['id' => 1, 'updated_at' => 'not a date'])
        ->conditionalValidator(Request::create('/readings/1'));

    expect($validator?->etag)->not->toBeNull()
        ->and($validator?->lastModified)->toBeNull();
});

it('honours a conditionalLastModifiedColumn override', function (): void {
    test()->travelTo(Carbon::parse('2026-08-26 12:00:05', 'UTC'));

    $model = new class extends Article
    {
        protected $table = 'articles';

        protected function conditionalLastModifiedColumn(): ?string
        {
            return null;
        }
    };

    $validator = $model->forceFill(['id' => 1, 'version' => 3, 'updated_at' => '2026-08-26 12:00:00'])
        ->conditionalValidator(Request::create('/articles/1'));

    expect($validator?->etag)->not->toBeNull()
        ->and($validator?->lastModified)->toBeNull();
});
