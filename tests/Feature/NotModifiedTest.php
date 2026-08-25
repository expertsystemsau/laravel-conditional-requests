<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware('conditional')->get('/articles', fn (): array => ['title' => 'Hello']);
});

it('returns 304 when the client already holds the current version', function (): void {
    $etag = $this->get('/articles')->headers->get('ETag');

    $this->get('/articles', ['If-None-Match' => $etag])
        ->assertStatus(304);
});

it('sends no body with a 304', function (): void {
    $etag = $this->get('/articles')->headers->get('ETag');

    expect($this->get('/articles', ['If-None-Match' => $etag])->getContent())->toBe('');
});

it('returns 200 with a body when the client holds a stale version', function (): void {
    $this->get('/articles', ['If-None-Match' => '"stale-tag"'])
        ->assertOk()
        ->assertJson(['title' => 'Hello']);
});

it('matches when the client sends a list of tags including the current one', function (): void {
    $etag = $this->get('/articles')->headers->get('ETag');

    $this->get('/articles', ['If-None-Match' => '"other", '.$etag])
        ->assertStatus(304);
});

it('treats a bare wildcard as a match', function (): void {
    $this->get('/articles', ['If-None-Match' => '*'])
        ->assertStatus(304);
});

it('ignores a malformed If-None-Match rather than failing', function (): void {
    // Symfony's Request::getETags() comma-splits the raw header without
    // validating it, so an unparseable value is indistinguishable from a
    // non-matching tag. What this covers is that garbage input is not fatal and
    // still yields a complete, freshly tagged 200 — not a separate parse path.
    $this->get('/articles', ['If-None-Match' => '"unterminated, W/, ***'])
        ->assertOk()
        ->assertHeader('ETag')
        ->assertJson(['title' => 'Hello']);
});
