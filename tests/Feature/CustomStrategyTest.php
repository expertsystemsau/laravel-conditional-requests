<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\ConditionalRequests;
use ExpertSystems\ConditionalRequests\Contracts\ValidatorStrategy;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    app(ConditionalRequests::class)->extend('flag-probe', fn (): ValidatorStrategy => fixedTagStrategy('from-flag'));
    app(ConditionalRequests::class)->extend('config-probe', fn (): ValidatorStrategy => fixedTagStrategy('from-config'));
});

it('uses the strategy named as a middleware flag', function (): void {
    Route::middleware('conditional:flag-probe')->get('/articles', fn (): array => ['title' => 'Hello']);

    expect($this->get('/articles')->headers->get('ETag'))->toBe('"from-flag"');
});

it('short-circuits to 304 against a flag-selected strategy', function (): void {
    Route::middleware('conditional:flag-probe')->get('/articles', fn (): array => ['title' => 'Hello']);

    $this->get('/articles', ['If-None-Match' => '"from-flag"'])->assertStatus(304);
});

it('uses the strategy named by the config key when no flag is given', function (): void {
    config()->set('laravel-conditional-requests.strategy', 'config-probe');

    Route::middleware('conditional')->get('/articles', fn (): array => ['title' => 'Hello']);

    expect($this->get('/articles')->headers->get('ETag'))->toBe('"from-config"');
});

it('lets a middleware flag override the config key', function (): void {
    config()->set('laravel-conditional-requests.strategy', 'config-probe');

    Route::middleware('conditional:flag-probe')->get('/articles', fn (): array => ['title' => 'Hello']);

    expect($this->get('/articles')->headers->get('ETag'))->toBe('"from-flag"');
});

it('fails loudly when a flag names a strategy nobody registered', function (): void {
    Route::middleware('conditional:not-registered')->get('/articles', fn (): array => ['title' => 'Hello']);

    $this->withoutExceptionHandling();

    // The message must name the strategy that was asked for and point at what
    // is available, without this test pinning the full registered list — a
    // fourth built-in strategy is a feature, not a reason for this to fail.
    expect(fn () => $this->get('/articles'))->toThrow(function (InvalidArgumentException $e): void {
        expect($e->getMessage())
            ->toContain('[not-registered] is not registered')
            ->toContain('Registered:')
            ->toContain('body');
    });
});
