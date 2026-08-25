<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware('conditional')->get('/articles', fn (): array => ['title' => 'Hello']);
});

it('attaches an ETag to a successful response', function (): void {
    $this->get('/articles')
        ->assertOk()
        ->assertHeader('ETag');
});

it('attaches a strong ETag by default', function (): void {
    $etag = $this->get('/articles')->headers->get('ETag');

    expect($etag)->toStartWith('"')->not->toStartWith('W/');
});

it('attaches a weak ETag when configured to', function (): void {
    config()->set('laravel-conditional-requests.weak', true);

    expect($this->get('/articles')->headers->get('ETag'))->toStartWith('W/"');
});

it('attaches the same ETag to repeated identical responses', function (): void {
    expect($this->get('/articles')->headers->get('ETag'))
        ->toBe($this->get('/articles')->headers->get('ETag'));
});

it('still returns the response body', function (): void {
    $this->get('/articles')->assertJson(['title' => 'Hello']);
});
