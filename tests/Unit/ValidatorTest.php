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

it('rejects an empty entity tag', function (): void {
    new Validator('');
})->throws(InvalidArgumentException::class, 'An entity tag cannot be empty.');

it('rejects a tag that normalises away to nothing', function (): void {
    new Validator('W/""');
})->throws(InvalidArgumentException::class, 'An entity tag cannot be empty.');

it('rejects a tag containing a double quote', function (): void {
    new Validator('abc"123');
})->throws(InvalidArgumentException::class, 'contains a double quote');

it('rejects a tag containing a comma', function (): void {
    // Legal etagc, but If-Match and If-None-Match carry a #entity-tag list, so
    // an echoed tag holding one splits into two malformed members that can
    // never match — a permanent 412 on the resource. Unreachable through the
    // package's hex-emitting strategies; reachable through a custom one.
    new Validator('a,b');
})->throws(InvalidArgumentException::class, 'contains a comma');

it('rejects a tag containing a control character', function (): void {
    new Validator("abc\n123");
})->throws(InvalidArgumentException::class, 'An entity tag cannot contain control characters.');

it('rejects a tag containing a DEL character', function (): void {
    new Validator("abc\x7f123");
})->throws(InvalidArgumentException::class, 'An entity tag cannot contain control characters.');
