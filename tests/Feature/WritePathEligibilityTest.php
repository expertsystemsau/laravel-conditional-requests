<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Http\Middleware\Conditional;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Article::create(['title' => 'Hello', 'version' => 1]);
});

function eligibilityRoutes(string $middleware = 'conditional:required'): void
{
    Route::middleware([SubstituteBindings::class, $middleware])
        ->put('/articles/{article}', fn (Article $article): array => ['title' => $article->title])
        ->name('articles.update');

    Route::middleware([SubstituteBindings::class, $middleware])
        ->post('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);
}

it('leaves every write unguarded when the package is disabled', function (): void {
    config()->set('laravel-conditional-requests.enabled', false);

    eligibilityRoutes();

    $this->put('/articles/1')->assertOk();
});

it('leaves a URI excluded write unguarded', function (): void {
    config()->set('laravel-conditional-requests.exclude', ['articles/*']);

    eligibilityRoutes();

    $this->put('/articles/1')->assertOk();
});

it('leaves a name excluded write unguarded', function (): void {
    config()->set('laravel-conditional-requests.exclude', ['articles.*']);

    eligibilityRoutes();

    $this->put('/articles/1')->assertOk();
});

it('guards an unsafe method that the methods config does not list', function (): void {
    // The default is ['GET', 'HEAD']. If it gated the write path,
    // conditional:required would do nothing at all out of the box.
    expect(config('laravel-conditional-requests.methods'))->toBe(['GET', 'HEAD']);

    eligibilityRoutes();

    $this->post('/articles/1')->assertStatus(428);
});

it('does not put an unsafe method on the read path by listing it', function (): void {
    config()->set('laravel-conditional-requests.methods', ['GET', 'HEAD', 'POST']);

    eligibilityRoutes();

    $this->post('/articles/1', [], ['If-Match' => '*'])
        ->assertOk()
        ->assertHeaderMissing('ETag');
});

it('leaves a write on a strategy that cannot answer completely untouched', function (): void {
    // The pass-through that keeps a plain `conditional` route behaving exactly
    // as it did before v0.3. The default strategy is "body", which describes a
    // response that does not exist yet, so the guard has nothing to compare and
    // the write proceeds — stale If-Match and all. The controller counter is
    // the assertion that matters: 200 alone is also what a 412, a 428 or a 500
    // would leave behind if this branch stopped being a pass-through, and the
    // suite would not notice.
    $runs = 0;

    Route::middleware([SubstituteBindings::class, 'conditional'])
        ->post('/articles/{article}', function (Article $article) use (&$runs): array {
            $runs++;

            return ['title' => $article->title];
        });

    $this->post('/articles/1', [], ['If-Match' => '"stale-tag"'])
        ->assertOk()
        ->assertJson(['title' => 'Hello']);

    expect($runs)->toBe(1);
});

it('refuses an If-Match rather than erroring under kernel global placement', function (): void {
    // Nothing has been routed when a globally pushed middleware runs, so no
    // model can be found and every resource reads as absent. Fail closed.
    config()->set('laravel-conditional-requests.strategy', 'model');

    app(Kernel::class)->pushMiddleware(Conditional::class);

    Route::middleware(SubstituteBindings::class)
        ->put('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);

    $this->put('/articles/1', [], ['If-Match' => '"anything"'])->assertStatus(412);
});

it('lets an unguarded write through under kernel global placement', function (): void {
    config()->set('laravel-conditional-requests.strategy', 'model');

    app(Kernel::class)->pushMiddleware(Conditional::class);

    Route::middleware(SubstituteBindings::class)
        ->put('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);

    $this->put('/articles/1')->assertOk();
});

it('refuses an If-Match when conditional runs before route model binding', function (): void {
    // The read path degrades to a slower 304 when the ordering is wrong. The
    // write path degrades to a 412 on every write, which is why the ordering
    // is a requirement for a guarded route rather than a recommendation.
    Route::middleware(['conditional:model', SubstituteBindings::class])
        ->put('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);

    $this->put('/articles/1', [], ['If-Match' => '*'])->assertStatus(412);
});

it('lets an If-None-Match wildcard through when no model can be found', function (): void {
    // The other side of the same coin: absent-by-default means the create
    // guard cannot detect a duplicate, so it passes rather than refusing.
    Route::middleware(['conditional:model', SubstituteBindings::class])
        ->put('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);

    $this->put('/articles/1', [], ['If-None-Match' => '*'])->assertOk();
});

it('leaves OPTIONS on the read path', function (): void {
    // OPTIONS is safe, so it takes the read path and never reaches the guard —
    // no 428 despite `required`. The methods config then keeps it off the read
    // path too, so no validator either. Registered explicitly because Laravel
    // answers an unmatched OPTIONS with a synthetic route carrying no
    // middleware at all, which would pass this test for the wrong reason.
    Route::middleware('conditional:required')
        ->options('/articles/1', fn (): array => ['ok' => true]);

    $this->call('OPTIONS', '/articles/1')
        ->assertOk()
        ->assertHeaderMissing('ETag');
});

it('leaves the read path completely unchanged', function (): void {
    Route::middleware([SubstituteBindings::class, 'conditional:model'])
        ->get('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);

    $etag = (string) $this->get('/articles/1')->headers->get('ETag');

    $this->get('/articles/1', ['If-None-Match' => $etag])->assertStatus(304);
    $this->head('/articles/1', ['If-None-Match' => $etag])->assertStatus(304);
});

it('never sends a validator on a guarded write that succeeds', function (): void {
    eligibilityRoutes();

    $this->put('/articles/1', [], ['If-Match' => '*'])
        ->assertOk()
        ->assertHeaderMissing('ETag');
});
