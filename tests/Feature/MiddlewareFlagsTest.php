<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\ConditionalRequests;
use ExpertSystems\ConditionalRequests\Contracts\ValidatorStrategy;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    // Overriding the registered `model` entry is what makes the implication
    // observable: whatever tag comes back names the strategy that was chosen.
    app(ConditionalRequests::class)->extend('model', fn (): ValidatorStrategy => fixedTagStrategy('from-model'));
    app(ConditionalRequests::class)->extend('flag-probe', fn (): ValidatorStrategy => fixedTagStrategy('from-flag'));
});

it('accepts a reserved flag instead of treating it as a strategy name', function (): void {
    Route::middleware('conditional:required')->get('/articles', fn (): array => ['title' => 'Hello']);

    expect($this->get('/articles')->headers->get('ETag'))->toBe('"from-model"');
});

it('accepts lock as a reserved flag too', function (): void {
    Route::middleware('conditional:lock')->get('/articles', fn (): array => ['title' => 'Hello']);

    expect($this->get('/articles')->headers->get('ETag'))->toBe('"from-model"');
});

it('does not care about flag order', function (): void {
    Route::middleware('conditional:lock,required')->get('/articles', fn (): array => ['title' => 'Hello']);
    Route::middleware('conditional:required,lock')->get('/posts', fn (): array => ['title' => 'Hello']);

    expect($this->get('/articles')->headers->get('ETag'))->toBe('"from-model"')
        ->and($this->get('/posts')->headers->get('ETag'))->toBe('"from-model"');
});

it('lets an explicit strategy flag win over a reserved word', function (): void {
    Route::middleware('conditional:flag-probe,required')->get('/articles', fn (): array => ['title' => 'Hello']);

    expect($this->get('/articles')->headers->get('ETag'))->toBe('"from-flag"');
});

it('tolerates whitespace in a flag list', function (): void {
    Route::middleware('conditional:flag-probe, required')->get('/articles', fn (): array => ['title' => 'Hello']);

    expect($this->get('/articles')->headers->get('ETag'))->toBe('"from-flag"');
});
