<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Validators\Validator;

it('stores a bare entity tag unchanged', function (): void {
    expect((new Validator('abc123'))->etag)->toBe('abc123');
});

it('strips surrounding quotes so tags are never double quoted', function (): void {
    expect((new Validator('"abc123"'))->etag)->toBe('abc123');
});

it('strips a weak prefix from the stored tag', function (): void {
    expect((new Validator('W/"abc123"'))->etag)->toBe('abc123');
});

it('trims surrounding whitespace', function (): void {
    expect((new Validator('  abc123  '))->etag)->toBe('abc123');
});

it('is strong by default', function (): void {
    expect((new Validator('abc123'))->weak)->toBeFalse();
});

it('renders a strong header value', function (): void {
    expect((new Validator('abc123'))->header())->toBe('"abc123"');
});

it('renders a weak header value', function (): void {
    expect((new Validator('abc123', weak: true))->header())->toBe('W/"abc123"');
});
