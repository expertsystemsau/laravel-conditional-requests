<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\ConditionalRequests;

it('resolves the singleton', function () {
    expect(app(ConditionalRequests::class))->toBeInstanceOf(ConditionalRequests::class);
});

it('returns the same instance from the container', function () {
    expect(app(ConditionalRequests::class))->toBe(app(ConditionalRequests::class));
});

it('merges the package config', function () {
    expect(config('laravel-conditional-requests.placeholder'))->toBe('default');
});

it('loads the package translations', function () {
    expect(trans('laravel-conditional-requests::messages.placeholder'))->toBe('ConditionalRequests placeholder translation.');
});

it('registers the artisan command', function () {
    $this->artisan('laravel-conditional-requests:placeholder')
        ->expectsOutputToContain('ConditionalRequests placeholder command executed.')
        ->assertSuccessful();
});
