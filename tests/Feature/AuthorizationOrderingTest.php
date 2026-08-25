<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * The documented hazard and its documented mitigation, both pinned.
 *
 * A pre-controller 304 is answered by `conditional` and never reaches whatever
 * comes after it in the pipeline, so where the middleware sits relative to an
 * authorization check decides whether a revoked client still gets its content
 * confirmed. Neither half is an accident of the current implementation — both
 * are promises the README makes — so a refactor must not be able to move
 * either one silently.
 *
 * The ordering holds because Authorize is in the kernel's $middlewarePriority
 * and `conditional` is not: SortedMiddleware only ever reorders priority
 * middleware among themselves, so a non-priority entry keeps the position the
 * route gave it.
 */
beforeEach(function (): void {
    Article::create(['title' => 'Hello', 'version' => 1]);
});

/**
 * Register the guarded route and hand back the switch the gate reads.
 *
 * The gate takes a nullable user so it is evaluated for a guest rather than
 * short-circuited into an authentication failure — the denial under test is
 * authorization, not authentication.
 *
 * @param  list<class-string|string>  $middleware
 */
function articleRouteGuardedBy(array $middleware, ?bool &$allowed): void
{
    $allowed = true;

    Gate::define('view-article', function (?Authenticatable $user, Article $article) use (&$allowed): bool {
        return (bool) $allowed;
    });

    Route::middleware($middleware)->get('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);
}

it('answers 304 for a forbidden record when conditional runs before authorization', function (): void {
    articleRouteGuardedBy([
        SubstituteBindings::class,
        'conditional:model',
        Authorize::class.':view-article,article',
    ], $allowed);

    $etag = $this->get('/articles/1')->headers->get('ETag');

    expect($etag)->not->toBeNull();

    $allowed = false;

    // The gate really does deny now: the same request without a matching tag
    // is refused, which is what makes the line below a bypass rather than a
    // check that never fired.
    $this->get('/articles/1')->assertStatus(403);

    // The hazard, exactly as the README describes it: the client's access is
    // gone, and it is still told its copy is current. Nothing that would have
    // said otherwise runs.
    $this->get('/articles/1', ['If-None-Match' => $etag])->assertStatus(304);
});

it('reaches authorization for a wildcard, which no client can be holding', function (): void {
    articleRouteGuardedBy([
        SubstituteBindings::class,
        'conditional:model',
        Authorize::class.':view-article,article',
    ], $allowed);

    $allowed = false;

    // `If-None-Match: *` matches whatever the record's validator turns out to
    // be, so a short-circuit on it would answer 304 for any record that
    // exists and 404 for one that does not — an existence oracle behind a gate
    // that never runs, needing no tag and no prior access. The wildcard is
    // therefore refused the short-circuit, the gate runs, and the client is
    // told what it is actually entitled to be told.
    $this->get('/articles/1', ['If-None-Match' => '*'])->assertStatus(403);
});

it('reaches authorization for a weak-form wildcard, which Symfony also matches on', function (): void {
    articleRouteGuardedBy([
        SubstituteBindings::class,
        'conditional:model',
        Authorize::class.':view-article,article',
    ], $allowed);

    $allowed = false;

    // `W/*` is not a wildcard under RFC 9110 §13.1.2's grammar, but
    // Response::isNotModified() drops the weakness prefix before it tests for
    // `*` and so treats it as one. Anything the short-circuit lets through on
    // the strength of a tag Symfony will then call a wildcard is the same
    // oracle by another spelling, so the weakness prefix comes off here first
    // too and the gate gets the request.
    $this->get('/articles/1', ['If-None-Match' => 'W/*'])->assertStatus(403);

    // Nothing changes when a tag the client does not hold is sent with it.
    $this->get('/articles/1', ['If-None-Match' => '"stale-tag", W/*'])->assertStatus(403);
});

it('answers 403 and never 304 when authorization runs before conditional', function (): void {
    articleRouteGuardedBy([
        SubstituteBindings::class,
        Authorize::class.':view-article,article',
        'conditional:model',
    ], $allowed);

    $etag = $this->get('/articles/1')->headers->get('ETag');

    expect($etag)->not->toBeNull();

    $allowed = false;

    // The mitigation: the request is rejected before the strategy is ever
    // consulted, so a still-matching tag buys the client nothing.
    $this->get('/articles/1', ['If-None-Match' => $etag])->assertStatus(403);
});
