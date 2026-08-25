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
