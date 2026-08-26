<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * A record written at a known instant, with the clock parked well after it so
 * its second has elapsed and a date can be published at all.
 */
beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-08-26 12:00:00', 'UTC'));

    Article::create(['title' => 'Hello']);

    $this->travelTo(Carbon::parse('2026-08-26 12:00:05', 'UTC'));
});

function datedArticleRoutes(string $middleware = 'conditional:model'): void
{
    Route::middleware([SubstituteBindings::class, $middleware])
        ->get('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);

    Route::middleware([SubstituteBindings::class, 'conditional:required'])
        ->put('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);
}

it('publishes the records modification date alongside the tag', function (): void {
    datedArticleRoutes();

    $this->get('/articles/1')
        ->assertOk()
        ->assertHeader('ETag')
        ->assertHeader('Last-Modified', 'Wed, 26 Aug 2026 12:00:00 GMT');
});

it('publishes no date from the body hash strategy', function (): void {
    // A body hash fingerprints content, not the time it changed. The only
    // dates it could invent — now, or the response's own Date — would advertise
    // "modified just now" on every response and change on every request.
    Route::middleware('conditional')->get('/report', fn (): array => ['ok' => true]);

    $this->get('/report')
        ->assertOk()
        ->assertHeader('ETag')
        ->assertHeaderMissing('Last-Modified');
});

it('publishes no date when the config key is off', function (): void {
    config()->set('laravel-conditional-requests.last_modified', false);

    datedArticleRoutes();

    $this->get('/articles/1')
        ->assertOk()
        ->assertHeader('ETag')
        ->assertHeaderMissing('Last-Modified');
});

it('leaves the responses cache policy exactly as it found it', function (): void {
    // Symfony recomputes an unset Cache-Control to "private, must-revalidate"
    // the moment a Last-Modified appears, which permits heuristic freshness —
    // a browser reusing its copy without revalidating at all. Attaching a
    // validator must not be what stops a resource being validated.
    datedArticleRoutes();

    $this->get('/articles/1')
        ->assertHeader('Last-Modified', 'Wed, 26 Aug 2026 12:00:00 GMT')
        ->assertHeader('Cache-Control', 'no-cache, private');
});

it('leaves an application set cache policy alone', function (): void {
    Route::middleware([SubstituteBindings::class, 'conditional:model'])
        ->get('/articles/{article}', fn (Article $article) => response()
            ->json(['title' => $article->title])
            ->header('Cache-Control', 'public, max-age=60'));

    // Symfony reorders an explicit policy's directives when it parses them, so
    // this is the normalised form of "public, max-age=60" — unchanged by the
    // date, because an explicit policy suppresses the recomputation entirely.
    $this->get('/articles/1')
        ->assertHeader('Last-Modified', 'Wed, 26 Aug 2026 12:00:00 GMT')
        ->assertHeader('Cache-Control', 'max-age=60, public');
});

it('does not overwrite a date the application set itself', function (): void {
    Route::middleware([SubstituteBindings::class, 'conditional:model'])
        ->get('/articles/{article}', fn (Article $article) => response()
            ->json(['title' => $article->title])
            ->header('Last-Modified', 'Tue, 25 Aug 2026 09:00:00 GMT'));

    $this->get('/articles/1')
        ->assertHeader('ETag')
        ->assertHeader('Last-Modified', 'Tue, 25 Aug 2026 09:00:00 GMT');
});

it('survives a malformed date the application set itself', function (): void {
    // HeaderBag::getDate() throws RuntimeException on an unparseable value, so
    // nothing here may ask the response for its Last-Modified as a date.
    Route::middleware([SubstituteBindings::class, 'conditional:model'])
        ->get('/articles/{article}', fn (Article $article) => response()
            ->json(['title' => $article->title])
            ->header('Last-Modified', 'nonsense'));

    $this->get('/articles/1')
        ->assertOk()
        ->assertHeader('Last-Modified', 'nonsense');
});

it('publishes no date while the record is still inside the second it changed in', function (): void {
    $this->travelTo(Carbon::parse('2026-08-26 12:00:10', 'UTC'));

    Article::query()->findOrFail(1)->update(['title' => 'Changed']);

    $this->travelTo(Carbon::parse('2026-08-26 12:00:10.900000', 'UTC'));

    datedArticleRoutes();

    $this->get('/articles/1')
        ->assertOk()
        ->assertHeader('ETag')
        ->assertHeaderMissing('Last-Modified');
});

it('gives an unsafe request no date of its own', function (): void {
    datedArticleRoutes();

    $this->put('/articles/1', [], ['If-Match' => '*'])
        ->assertOk()
        ->assertHeaderMissing('ETag')
        ->assertHeaderMissing('Last-Modified');
});
