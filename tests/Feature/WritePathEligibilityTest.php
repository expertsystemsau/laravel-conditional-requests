<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Http\Middleware\Conditional;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Probe;
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

    $this->put('/articles/1')
        ->assertOk()
        ->assertJson(['title' => 'Hello']);
});

it('leaves a URI excluded write unguarded', function (): void {
    config()->set('laravel-conditional-requests.exclude', ['articles/*']);

    eligibilityRoutes();

    $this->put('/articles/1')
        ->assertOk()
        ->assertJson(['title' => 'Hello']);
});

it('leaves a name excluded write unguarded', function (): void {
    config()->set('laravel-conditional-requests.exclude', ['articles.*']);

    eligibilityRoutes();

    $this->put('/articles/1')
        ->assertOk()
        ->assertJson(['title' => 'Hello']);
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

it('leaves an unconditional write on a strategy that cannot answer completely untouched', function (): void {
    // The pass-through that keeps a plain `conditional` route behaving exactly
    // as it did before v0.3. The default strategy is "body", which describes a
    // response that does not exist yet, so the guard has nothing to compare —
    // and a client that asked for nothing is given the v0.2 behaviour. The
    // controller counter is the assertion that matters: 200 alone is also what
    // a 412, a 428 or a 500 would leave behind if this branch stopped being a
    // pass-through, and the suite would not notice.
    //
    // Amended with the v0.3 write-path sweep: this previously sent a stale
    // If-Match here and asserted the write applied. See the two tests below —
    // silently discarding a precondition is the failure this package exists to
    // prevent, so that half moved rather than being dropped.
    $runs = 0;

    Route::middleware([SubstituteBindings::class, 'conditional'])
        ->post('/articles/{article}', function (Article $article) use (&$runs): array {
            $runs++;

            return ['title' => $article->title];
        });

    $this->post('/articles/1')
        ->assertOk()
        ->assertJson(['title' => 'Hello']);

    expect($runs)->toBe(1);
});

it('refuses a precondition a strategy cannot evaluate rather than discarding it', function (): void {
    // `Route::resource(...)->middleware('conditional')` is the natural thing to
    // write, "body" is the default strategy, and a client sending a correct
    // optimistic-concurrency header used to get a 200 with no signal at all.
    // The route looked guarded and was not.
    $runs = 0;

    Route::middleware([SubstituteBindings::class, 'conditional'])
        ->patch('/articles/{article}', function (Article $article) use (&$runs): array {
            $runs++;
            $article->update(['title' => 'Clobbered']);

            return ['title' => $article->title];
        });

    foreach ([['If-Match' => '"stale-tag"'], ['If-Match' => '*'], ['If-None-Match' => '*']] as $headers) {
        $this->patch('/articles/1', [], $headers)->assertStatus(412);
    }

    expect($runs)->toBe(0)
        ->and(Article::query()->findOrFail(1)->title)->toBe('Hello');
});

it('refuses a precondition on an excluded strategy write route only when one is sent', function (): void {
    // The refusal is per-request, not per-route: the guard stays opt-in, so an
    // unconditional write to the same route still passes through.
    Route::middleware([SubstituteBindings::class, 'conditional:body'])
        ->put('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);

    $this->put('/articles/1', [], ['If-Match' => '"anything"'])->assertStatus(412);
    $this->put('/articles/1', [], ['If-None-Match' => '   '])->assertOk();
    $this->put('/articles/1')->assertOk();
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

    $this->put('/articles/1')
        ->assertOk()
        ->assertJson(['title' => 'Hello']);
});

it('defers to the route level guard under kernel global placement', function (): void {
    // The default strategy — `body` — cannot produce a validator before the
    // controller runs, and a globally pushed instance runs ahead of the router
    // where there are no flags to read, so it always resolves that default.
    // Refusing a precondition there refuses it on behalf of a route that has
    // not been chosen yet: every `conditional:required` write in the
    // application answers 412 without its own guard ever running, the writes
    // carrying the correct If-Match included. Registering `conditional`
    // globally for read-path ETags must leave those guards working.
    expect(config('laravel-conditional-requests.strategy'))->toBe('body');

    app(Kernel::class)->pushMiddleware(Conditional::class);

    $runs = 0;

    Route::middleware([SubstituteBindings::class, 'conditional:model'])
        ->get('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);

    Route::middleware([SubstituteBindings::class, 'conditional:required'])
        ->put('/articles/{article}', function (Article $article) use (&$runs): array {
            $runs++;

            return ['title' => $article->title];
        });

    $etag = (string) $this->get('/articles/1')->headers->get('ETag');

    $this->put('/articles/1', [], ['If-Match' => $etag])
        ->assertOk()
        ->assertJson(['title' => 'Hello']);

    expect($runs)->toBe(1);
});

it('refuses a stale If-Match from the route level guard under kernel global placement', function (): void {
    // The other half: deferring is not passing the write through unguarded.
    // The probe middleware is what tells the two 412s apart — it sits between
    // the global instance and the route's own guard, so it runs only if the
    // global one deferred rather than refusing on the route's behalf.
    expect(config('laravel-conditional-requests.strategy'))->toBe('body');

    app(Kernel::class)->pushMiddleware(Conditional::class);

    $runs = 0;
    Probe::$reached = 0;

    Route::middleware([Probe::class, SubstituteBindings::class, 'conditional:required'])
        ->put('/articles/{article}', function (Article $article) use (&$runs): array {
            $runs++;
            $article->update(['title' => 'Clobbered']);

            return ['title' => $article->title];
        });

    $this->put('/articles/1', [], ['If-Match' => '"stale-tag"'])->assertStatus(412);

    expect(Probe::$reached)->toBe(1)
        ->and($runs)->toBe(0)
        ->and(Article::query()->findOrFail(1)->title)->toBe('Hello');
});

it('refuses an If-Match when conditional runs before route model binding', function (): void {
    // The read path degrades to a slower 304 when the ordering is wrong. The
    // write path degrades to a 412 on every write, which is why the ordering
    // is a requirement for a guarded route rather than a recommendation.
    Route::middleware(['conditional:model', SubstituteBindings::class])
        ->put('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);

    $this->put('/articles/1', [], ['If-Match' => '*'])->assertStatus(412);
});

it('refuses an If-None-Match wildcard when conditional runs before route model binding', function (): void {
    // Amended with the v0.3 write-path sweep. This previously asserted the
    // create guard passed here, on the reasoning that a misordered guard reads
    // every resource as absent — which made the misordering far worse than the
    // README claimed: it did not "stop writes", it turned the route into one
    // where `If-None-Match: *` overwrote live records. The strategy now reports
    // an unsubstituted parameter as "cannot tell", and the create guard fails
    // closed on that.
    Route::middleware(['conditional:model', SubstituteBindings::class])
        ->put('/articles/{article}', function (Article $article): array {
            $article->update(['title' => 'Clobbered']);

            return ['title' => $article->title];
        });

    $this->put('/articles/1', [], ['If-None-Match' => '*'])->assertStatus(412);

    expect(Article::query()->findOrFail(1)->title)->toBe('Hello');
});

it('refuses an If-None-Match wildcard under kernel global placement', function (): void {
    config()->set('laravel-conditional-requests.strategy', 'model');

    app(Kernel::class)->pushMiddleware(Conditional::class);

    Route::middleware(SubstituteBindings::class)
        ->put('/articles/{article}', function (Article $article): array {
            $article->update(['title' => 'Clobbered']);

            return ['title' => $article->title];
        });

    $this->put('/articles/1', [], ['If-None-Match' => '*'])->assertStatus(412);

    expect(Article::query()->findOrFail(1)->title)->toBe('Hello');
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
