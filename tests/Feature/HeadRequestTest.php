<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware('conditional')->get('/articles', fn (): array => ['title' => 'Hello']);
});

it('gives a HEAD request the same ETag as the equivalent GET', function (): void {
    expect($this->head('/articles')->headers->get('ETag'))
        ->toBe($this->get('/articles')->headers->get('ETag'));
});

it('returns 304 for a HEAD request holding the current version', function (): void {
    $etag = $this->get('/articles')->headers->get('ETag');

    $this->head('/articles', ['If-None-Match' => $etag])->assertStatus(304);
});

it('keeps the ETag on a GET 304 so the client can refresh its cache entry', function (): void {
    $etag = $this->get('/articles')->headers->get('ETag');

    $response = $this->get('/articles', ['If-None-Match' => $etag]);

    expect($response->status())->toBe(304)
        ->and($response->headers->get('ETag'))->toBe($etag);
});

it('keeps the ETag on a HEAD 304 so the client can refresh its cache entry', function (): void {
    $etag = $this->get('/articles')->headers->get('ETag');

    $response = $this->head('/articles', ['If-None-Match' => $etag]);

    expect($response->status())->toBe(304)
        ->and($response->headers->get('ETag'))->toBe($etag);
});

it('sends no body on a HEAD request', function (): void {
    expect($this->head('/articles')->getContent())->toBe('');
});

it('sends no body on a HEAD request the middleware skips', function (): void {
    // An ineligible response never reaches the validator, but it must still
    // leave the middleware with an empty body: under global placement nothing
    // downstream re-prepares it, so the middleware's own nulling is the only
    // thing standing between a HEAD request and a full payload.
    config()->set('laravel-conditional-requests.exclude', ['internal/*']);

    Route::middleware('conditional')->get('/internal/metrics', fn () => response('metrics payload'));

    $response = $this->head('/internal/metrics');

    expect($response->getContent())->toBe('')
        ->and($response->headers->get('ETag'))->toBeNull();
});

it('gives an error response no validator and no body on a HEAD request', function (): void {
    // Laravel's routing Pipeline catches the exception and renders it inside
    // $next(), so the 500 comes back to the middleware as an ordinary response.
    // What this covers is that an error never gets a validator and that the
    // middleware's single exit still empties the body — not the finally block,
    // which is not reached on this path.
    Route::middleware('conditional')->get('/boom', function (): never {
        throw new RuntimeException('kaboom');
    });

    $response = $this->head('/boom');

    expect($response->status())->toBe(500)
        ->and($response->headers->get('ETag'))->toBeNull()
        ->and($response->getContent())->toBe('');
});

it('restores the request method when an exception escapes the middleware', function (): void {
    $captured = null;

    Route::middleware('conditional')->get('/boom', function () use (&$captured): never {
        $captured = request();

        throw new RuntimeException('kaboom');
    });

    // Without exception handling the throw propagates out through handle(),
    // which is the only path where the finally block is load-bearing.
    try {
        $this->withoutExceptionHandling()->head('/boom');
    } catch (RuntimeException) {
        // Expected — the assertion is about what the finally left behind.
    }

    expect($captured?->getMethod())->toBe('HEAD');
});
