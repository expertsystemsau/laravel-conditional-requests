<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\ConditionalRequests;
use Illuminate\Contracts\Console\Kernel;

it('resolves the singleton', function () {
    expect(app(ConditionalRequests::class))->toBeInstanceOf(ConditionalRequests::class);
});

it('returns the same instance from the container', function () {
    expect(app(ConditionalRequests::class))->toBe(app(ConditionalRequests::class));
});

it('exposes the v0.1 configuration defaults', function (): void {
    expect(config('laravel-conditional-requests.enabled'))->toBeTrue()
        ->and(config('laravel-conditional-requests.strategy'))->toBe('body')
        ->and(config('laravel-conditional-requests.hash'))->toBe('xxh128')
        ->and(config('laravel-conditional-requests.weak'))->toBeFalse()
        ->and(config('laravel-conditional-requests.max_response_bytes'))->toBe(1_048_576)
        ->and(config('laravel-conditional-requests.methods'))->toBe(['GET', 'HEAD'])
        ->and(config('laravel-conditional-requests.exclude'))->toBe([]);
});

it('no longer registers the scaffold placeholder command', function (): void {
    expect(array_keys(app(Kernel::class)->all()))
        ->not->toContain('laravel-conditional-requests:placeholder');
});
