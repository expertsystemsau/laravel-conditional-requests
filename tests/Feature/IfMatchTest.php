<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Exceptions\PreconditionFailedException;
use ExpertSystems\ConditionalRequests\Exceptions\PreconditionRequiredException;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Article::create(['title' => 'Hello', 'version' => 1]);
});

/**
 * A read route and the four guarded write routes on one URI, with a counter
 * wired to every controller.
 *
 * The counter is what proves the guard runs *before* the controller: a 412 says
 * nothing about whether the write was attempted first, and a guard that refuses
 * after the fact has already lost the update it was protecting.
 */
function guardedArticleRoutes(?int &$runs, string $middleware = 'conditional:required'): void
{
    $runs = 0;

    $action = function (Article $article) use (&$runs): array {
        $runs++;

        return ['title' => $article->title];
    };

    Route::middleware([SubstituteBindings::class, 'conditional:model'])
        ->get('/articles/{article}', $action);

    foreach (['put', 'patch', 'delete', 'post'] as $method) {
        Route::middleware([SubstituteBindings::class, $middleware])
            ->{$method}('/articles/{article}', $action);
    }
}

it('lets a write through when If-Match names the current version', function (): void {
    guardedArticleRoutes($runs);

    $etag = $this->get('/articles/1')->headers->get('ETag');
    $runs = 0;

    $this->put('/articles/1', [], ['If-Match' => (string) $etag])
        ->assertOk()
        ->assertJson(['title' => 'Hello']);

    expect($runs)->toBe(1);
});

it('refuses a write whose If-Match names a stale version', function (): void {
    guardedArticleRoutes($runs);

    $this->put('/articles/1', [], ['If-Match' => '"stale-tag"'])->assertStatus(412);

    // The refusal has to happen before the controller, or the update it is
    // protecting has already been lost by the time we answer.
    expect($runs)->toBe(0);
});

it('answers a refused write with the packages message', function (): void {
    guardedArticleRoutes($runs);

    $this->putJson('/articles/1', [], ['If-Match' => '"stale-tag"'])
        ->assertStatus(412)
        ->assertJsonPath('message', trans(PreconditionFailedException::MESSAGE_KEY));
});

it('guards PATCH', function (): void {
    guardedArticleRoutes($runs);

    $this->patch('/articles/1', [], ['If-Match' => '"stale-tag"'])->assertStatus(412);
});

it('guards DELETE', function (): void {
    guardedArticleRoutes($runs);

    $this->delete('/articles/1', [], ['If-Match' => '"stale-tag"'])->assertStatus(412);
});

it('guards POST', function (): void {
    // Defect #1: werk365 guards PATCH only, and MDN's canonical mid-air
    // collision example is a wiki save over POST.
    guardedArticleRoutes($runs);

    $this->post('/articles/1', [], ['If-Match' => '"stale-tag"'])->assertStatus(412);
});

it('accepts a list of tags containing the current one', function (): void {
    guardedArticleRoutes($runs);

    $etag = $this->get('/articles/1')->headers->get('ETag');

    $this->put('/articles/1', [], ['If-Match' => '"other", '.(string) $etag])->assertOk();
});

it('refuses a weak If-Match even when its opaque tag is current', function (): void {
    guardedArticleRoutes($runs);

    $etag = (string) $this->get('/articles/1')->headers->get('ETag');

    // RFC 9110 §13.1.1 requires strong comparison. werk365 strips W/ by
    // default and lets this through — defect #4.
    $this->put('/articles/1', [], ['If-Match' => 'W/'.$etag])->assertStatus(412);
});

it('demands a precondition on a required route that carries none', function (): void {
    guardedArticleRoutes($runs);

    $this->put('/articles/1')->assertStatus(428);

    expect($runs)->toBe(0);
});

it('answers a demanded precondition with the packages message', function (): void {
    guardedArticleRoutes($runs);

    $this->putJson('/articles/1')
        ->assertStatus(428)
        ->assertJsonPath('message', trans(PreconditionRequiredException::MESSAGE_KEY));
});

it('lets an unguarded route write with no precondition at all', function (): void {
    // Without `required` the guard is per-client: send a precondition and it is
    // honoured, send none and nothing changes from v0.2.
    guardedArticleRoutes($runs, 'conditional:model');

    $this->put('/articles/1')->assertOk();

    expect($runs)->toBe(1);
});

it('still refuses a stale If-Match on a route without required', function (): void {
    guardedArticleRoutes($runs, 'conditional:model');

    $this->put('/articles/1', [], ['If-Match' => '"stale-tag"'])->assertStatus(412);
});

it('refuses a blank If-Match and leaves the record alone', function (): void {
    // The realistic shape is a client templating `If-Match: ${etag}` with an
    // empty variable. It asked to be guarded; collapsing the blank header to
    // "absent" let the write through unguarded on a route without `required`
    // and the record was clobbered. `If-Match: ,` is the same state — header
    // present, zero valid members — and already answered 412.
    Route::middleware([SubstituteBindings::class, 'conditional:model'])
        ->patch('/articles/{article}', function (Article $article): array {
            $article->update(['title' => 'Clobbered']);

            return ['title' => $article->title];
        });

    $this->patch('/articles/1', [], ['If-Match' => ''])->assertStatus(412);

    expect(Article::query()->findOrFail(1)->title)->toBe('Hello');
});

it('leaves the read path on the same URI untouched', function (): void {
    guardedArticleRoutes($runs);

    $this->get('/articles/1')->assertOk()->assertHeader('ETag');
});

it('gives an unsafe request no validator of its own', function (): void {
    guardedArticleRoutes($runs);

    $etag = $this->get('/articles/1')->headers->get('ETag');

    $this->put('/articles/1', [], ['If-Match' => (string) $etag])
        ->assertOk()
        ->assertHeaderMissing('ETag');
});

it('reruns the guard against the new version after a write', function (): void {
    guardedArticleRoutes($runs);

    $etag = (string) $this->get('/articles/1')->headers->get('ETag');

    $this->put('/articles/1', [], ['If-Match' => $etag])->assertOk();

    Article::query()->findOrFail(1)->update(['version' => 2]);

    // The token the client is still holding names the version it read, which
    // is exactly the lost update this phase exists to refuse.
    $this->put('/articles/1', [], ['If-Match' => $etag])->assertStatus(412);
});

it('lets the client recover by refetching', function (): void {
    guardedArticleRoutes($runs);

    Article::query()->findOrFail(1)->update(['version' => 2]);

    $this->put('/articles/1', [], ['If-Match' => (string) $this->get('/articles/1')->headers->get('ETag')])
        ->assertOk();
});
