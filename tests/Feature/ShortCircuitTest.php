<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Contracts\ValidatorStrategy;
use ExpertSystems\ConditionalRequests\Facades\ConditionalRequests;
use ExpertSystems\ConditionalRequests\Http\Middleware\Conditional;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Article::create(['title' => 'Hello', 'version' => 1]);
});

/**
 * Register the guarded route and wire a counter to the route closure.
 *
 * The counter is the only honest way to prove the short-circuit: a 304 status
 * says nothing about whether the controller ran to produce it. Only the closure
 * increments it, so an unchanged count is compute that was genuinely skipped.
 */
function articleRoute(?int &$runs, string $middleware = 'conditional:model'): void
{
    $runs = 0;

    Route::middleware([SubstituteBindings::class, $middleware])
        ->get('/articles/{article}', function (Article $article) use (&$runs): array {
            $runs++;

            return ['title' => $article->title];
        });
}

it('returns 304 without running the controller when the validator matches', function (): void {
    articleRoute($runs);

    $etag = $this->get('/articles/1')->headers->get('ETag');

    expect($etag)->not->toBeNull()
        ->and($runs)->toBe(1);

    $this->get('/articles/1', ['If-None-Match' => $etag])->assertStatus(304);

    expect($runs)->toBe(1);
});

it('sends no body with a short-circuited 304', function (): void {
    articleRoute($runs);

    $etag = $this->get('/articles/1')->headers->get('ETag');

    expect($this->get('/articles/1', ['If-None-Match' => $etag])->getContent())->toBe('');
});

it('keeps the ETag on a short-circuited 304', function (): void {
    articleRoute($runs);

    $etag = $this->get('/articles/1')->headers->get('ETag');

    expect($this->get('/articles/1', ['If-None-Match' => $etag])->headers->get('ETag'))->toBe($etag);
});

it('runs the controller when the client holds a stale tag', function (): void {
    articleRoute($runs);

    $this->get('/articles/1', ['If-None-Match' => '"stale-tag"'])
        ->assertOk()
        ->assertJson(['title' => 'Hello']);

    expect($runs)->toBe(1);
});

it('short-circuits a HEAD request with no body', function (): void {
    articleRoute($runs);

    $etag = $this->get('/articles/1')->headers->get('ETag');
    $runs = 0;

    $response = $this->head('/articles/1', ['If-None-Match' => $etag]);

    expect($response->status())->toBe(304)
        ->and($response->getContent())->toBe('')
        ->and($runs)->toBe(0);
});

it('does not short-circuit on a bare wildcard', function (): void {
    articleRoute($runs);

    // A wildcard matches any validator, so answering it early would confirm a
    // record to a client that holds nothing and has cleared nothing declared
    // after `conditional`. The 304 is still correct — it just has to be earned
    // the long way, and the counter is what proves the long way was taken.
    $this->get('/articles/1', ['If-None-Match' => '*'])->assertStatus(304);

    expect($runs)->toBe(1);
});

it('short-circuits on a concrete tag sent alongside a wildcard', function (): void {
    articleRoute($runs);

    $etag = $this->get('/articles/1')->headers->get('ETag');
    $runs = 0;

    // The wildcard is not the reason this matches: the client demonstrably
    // holds the current validator, so there is nothing left to leak.
    $this->get('/articles/1', ['If-None-Match' => $etag.', *'])->assertStatus(304);

    expect($runs)->toBe(0);
});

it('short-circuits on a weak form of the current tag sent alongside a wildcard', function (): void {
    articleRoute($runs);

    $etag = $this->get('/articles/1')->headers->get('ETag');
    $runs = 0;

    // Weak comparison, the same one Response::isNotModified() performs: a
    // W/-prefixed copy of a strong tag is still the client holding it.
    $this->get('/articles/1', ['If-None-Match' => '*, W/'.$etag])->assertStatus(304);

    expect($runs)->toBe(0);
});

it('does not short-circuit on a wildcard beside a tag that is not the current one', function (): void {
    articleRoute($runs);

    $this->get('/articles/1', ['If-None-Match' => '"stale-tag", *'])->assertStatus(304);

    expect($runs)->toBe(1);
});

it('does not short-circuit on a weak-form wildcard', function (): void {
    articleRoute($runs);

    // `W/*` is not valid under RFC 9110 §13.1.2's grammar, but Symfony strips
    // the weakness prefix before it tests for `*`, so isNotModified() reads it
    // as a wildcard and matches. A guard that tested the raw token would read
    // it as a concrete tag, let the short-circuit through, and hand back the
    // oracle a bare `*` no longer gives. It is a wildcard here too.
    $this->get('/articles/1', ['If-None-Match' => 'W/*'])->assertStatus(304);

    expect($runs)->toBe(1);
});

it('does not short-circuit on a weak-form wildcard beside a tag that is not the current one', function (): void {
    articleRoute($runs);

    $this->get('/articles/1', ['If-None-Match' => '"stale-tag", W/*'])->assertStatus(304);

    expect($runs)->toBe(1);
});

it('produces a 304 indistinguishable from one produced after the controller ran', function (): void {
    ConditionalRequests::extend('probe-response', fn (): ValidatorStrategy => fixedTagStrategy('probe-tag'));
    ConditionalRequests::extend('probe-request', fn (): ValidatorStrategy => fixedRequestTagStrategy('probe-tag'));

    Route::middleware('conditional:probe-response')->get('/long', fn (): array => ['title' => 'Hello']);
    Route::middleware('conditional:probe-request')->get('/short', fn (): array => ['title' => 'Hello']);

    $long = $this->get('/long', ['If-None-Match' => '"probe-tag"']);
    $short = $this->get('/short', ['If-None-Match' => '"probe-tag"']);

    $headers = static function (TestResponse $response): array {
        $headers = $response->headers->all();

        // The only header two responses a few microseconds apart may differ on.
        unset($headers['date']);
        ksort($headers);

        return $headers;
    };

    expect($short->status())->toBe(304)
        ->and($long->status())->toBe(304)
        ->and($short->getContent())->toBe($long->getContent())
        ->and($headers($short))->toBe($headers($long));
});

it('never short-circuits under a strategy that needs the response', function (): void {
    articleRoute($runs, 'conditional:body');

    $etag = $this->get('/articles/1')->headers->get('ETag');

    $this->get('/articles/1', ['If-None-Match' => $etag])->assertStatus(304);

    // A body hash cannot be known before the body exists, so the 304 costs a
    // full controller run — the v0.1 behaviour, unchanged.
    expect($runs)->toBe(2);
});

it('respects the disabled switch', function (): void {
    config()->set('laravel-conditional-requests.enabled', false);

    articleRoute($runs);

    $this->get('/articles/1', ['If-None-Match' => '*'])->assertOk();

    expect($runs)->toBe(1);
});

it('respects the configured methods', function (): void {
    config()->set('laravel-conditional-requests.methods', ['HEAD']);

    articleRoute($runs);

    $this->get('/articles/1', ['If-None-Match' => '*'])->assertOk();

    expect($runs)->toBe(1);
});

it('respects an excluded route', function (): void {
    config()->set('laravel-conditional-requests.exclude', ['articles/*']);

    articleRoute($runs);

    $this->get('/articles/1', ['If-None-Match' => '*'])->assertOk();

    expect($runs)->toBe(1);
});

it('falls through to the normal path when nothing has been routed yet', function (): void {
    // Kernel-global placement: the middleware runs before the router, so
    // $request->route() is null and no model can be found. It must degrade to
    // the v0.1 path rather than error — the tag still lands, via fromResponse,
    // but the controller runs and no compute is saved.
    config()->set('laravel-conditional-requests.strategy', 'model');

    app(Kernel::class)->pushMiddleware(Conditional::class);

    $runs = 0;

    Route::middleware(SubstituteBindings::class)
        ->get('/articles/{article}', function (Article $article) use (&$runs): array {
            $runs++;

            return ['title' => $article->title];
        });

    $etag = $this->get('/articles/1')->headers->get('ETag');

    expect($etag)->not->toBeNull();

    $this->get('/articles/1', ['If-None-Match' => $etag])->assertStatus(304);

    expect($runs)->toBe(2);
});
