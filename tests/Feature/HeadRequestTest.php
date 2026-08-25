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

it('keeps the ETag on a 304 so the client can refresh its cache entry', function (): void {
    $etag = $this->get('/articles')->headers->get('ETag');

    $response = $this->get('/articles', ['If-None-Match' => $etag]);

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
