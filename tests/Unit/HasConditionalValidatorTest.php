<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Note;
use Illuminate\Http\Request;

/**
 * A model hydrated by hand. forceFill() runs the same setAttribute() path
 * Eloquent uses when it hydrates from the database, so the raw attributes end
 * up in exactly the shape a real read produces — without needing one.
 *
 * @param  array<string, mixed>  $attributes
 */
function fixtureArticle(array $attributes): Article
{
    return (new Article)->forceFill($attributes);
}

it('derives a tag from the table, the key, and updated_at', function (): void {
    $validator = fixtureArticle([
        'id' => 1,
        'title' => 'Hello',
        'updated_at' => '2026-08-25 10:00:00',
    ])->conditionalValidator(Request::create('/articles/1'));

    expect($validator?->etag)->toBe(
        hash('xxh128', implode("\0", ['articles', '1', '2026-08-25 10:00:00'])),
    );
});

it('produces a strong validator', function (): void {
    $validator = fixtureArticle([
        'id' => 1,
        'updated_at' => '2026-08-25 10:00:00',
    ])->conditionalValidator(Request::create('/articles/1'));

    // Weak model tags cannot satisfy If-Match, which is the whole write path.
    expect($validator?->weak)->toBeFalse();
});

it('prefers an explicit version column over updated_at', function (): void {
    $validator = fixtureArticle([
        'id' => 1,
        'version' => 7,
        'updated_at' => '2026-08-25 10:00:00',
    ])->conditionalValidator(Request::create('/articles/1'));

    expect($validator?->etag)->toBe(hash('xxh128', implode("\0", ['articles', '1', '7'])));
});

it('falls back to updated_at when the version column is null', function (): void {
    $request = Request::create('/articles/1');

    expect(fixtureArticle(['id' => 1, 'version' => null, 'updated_at' => '2026-08-25 10:00:00'])->conditionalValidator($request)?->etag)
        ->toBe(fixtureArticle(['id' => 1, 'updated_at' => '2026-08-25 10:00:00'])->conditionalValidator($request)?->etag);
});

it('changes the tag when the version changes', function (): void {
    $request = Request::create('/articles/1');

    expect(fixtureArticle(['id' => 1, 'version' => 7])->conditionalValidator($request)?->etag)
        ->not->toBe(fixtureArticle(['id' => 1, 'version' => 8])->conditionalValidator($request)?->etag);
});

it('changes the tag when the key changes', function (): void {
    $request = Request::create('/articles/1');

    expect(fixtureArticle(['id' => 1, 'updated_at' => '2026-08-25 10:00:00'])->conditionalValidator($request)?->etag)
        ->not->toBe(fixtureArticle(['id' => 2, 'updated_at' => '2026-08-25 10:00:00'])->conditionalValidator($request)?->etag);
});

it('gives records in different tables different tags for the same key and timestamp', function (): void {
    $request = Request::create('/articles/1');

    $article = fixtureArticle(['id' => 1, 'updated_at' => '2026-08-25 10:00:00']);
    $note = (new Note)->forceFill(['id' => 1, 'updated_at' => '2026-08-25 10:00:00']);

    expect($article->conditionalValidator($request)?->etag)
        ->not->toBe($note->conditionalValidator($request)?->etag);
});

it('declines to produce a validator for an unsaved record', function (): void {
    expect((new Article)->conditionalValidator(Request::create('/articles')))->toBeNull();
});

it('declines to produce a validator when the record has no version at all', function (): void {
    expect(fixtureArticle(['id' => 1, 'title' => 'Hello'])->conditionalValidator(Request::create('/articles/1')))
        ->toBeNull();
});

it('declines to produce a validator when the record has a version but no key', function (): void {
    expect(fixtureArticle(['updated_at' => '2026-08-25 10:00:00'])->conditionalValidator(Request::create('/articles')))
        ->toBeNull();
});

it('reflects a real save', function (): void {
    $request = Request::create('/articles/1');

    $article = Article::create(['title' => 'Hello', 'version' => 1]);
    $before = $article->conditionalValidator($request)?->etag;

    $article->update(['version' => 2]);

    expect($before)->not->toBeNull()
        ->and($article->conditionalValidator($request)?->etag)->not->toBe($before);
});
