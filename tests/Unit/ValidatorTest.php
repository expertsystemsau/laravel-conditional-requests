<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Support\Carbon;

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

it('carries no modification date by default', function (): void {
    expect((new Validator('abc123'))->lastModified)->toBeNull();
});

it('keeps a modification date it is given', function (): void {
    $validator = new Validator('abc123', lastModified: new DateTimeImmutable('2026-08-26 12:00:00', new DateTimeZone('UTC')));

    expect($validator->lastModified?->format('Y-m-d H:i:s'))->toBe('2026-08-26 12:00:00');
});

it('floors a sub second modification date to the whole second', function (): void {
    // Last-Modified is an HTTP-date and an HTTP-date has one-second
    // resolution, so the stored value has to be exactly what goes on the wire.
    $validator = new Validator('abc123', lastModified: new DateTimeImmutable('2026-08-26 12:00:00.700000', new DateTimeZone('UTC')));

    expect($validator->lastModified?->format('Y-m-d H:i:s.u'))->toBe('2026-08-26 12:00:00.000000');
});

it('floors rather than rounds a date past the half second', function (): void {
    // Rounding up would advertise a modification that has not happened yet,
    // and would hide any change landing between the two.
    $validator = new Validator('abc123', lastModified: new DateTimeImmutable('2026-08-26 12:00:00.999999', new DateTimeZone('UTC')));

    expect($validator->lastModified?->format('Y-m-d H:i:s'))->toBe('2026-08-26 12:00:00');
});

it('floors towards the earlier second before the epoch', function (): void {
    $validator = new Validator('abc123', lastModified: new DateTimeImmutable('1969-12-31 23:59:59.500000', new DateTimeZone('UTC')));

    expect($validator->lastModified?->getTimestamp())->toBe(-1)
        ->and($validator->lastModified?->format('Y-m-d H:i:s'))->toBe('1969-12-31 23:59:59');
});

it('normalises a modification date to UTC', function (): void {
    $validator = new Validator('abc123', lastModified: new DateTimeImmutable('2026-08-26 22:00:00', new DateTimeZone('Australia/Melbourne')));

    expect($validator->lastModified?->getTimezone()->getName())->toBe('UTC')
        ->and($validator->lastModified?->format('Y-m-d H:i:s'))->toBe('2026-08-26 12:00:00');
});

it('copies a mutable date so a later change cannot rewrite the validator', function (): void {
    // Eloquent hands out Illuminate\Support\Carbon, which extends the mutable
    // DateTime. readonly protects the reference, not the object behind it.
    $date = Carbon::parse('2026-08-26 12:00:00', 'UTC');

    $validator = new Validator('abc123', lastModified: $date);

    $date->addDay();

    expect($validator->lastModified?->format('Y-m-d H:i:s'))->toBe('2026-08-26 12:00:00');
});

it('keeps the modification date on a weak validator', function (): void {
    $validator = new Validator('abc123', true, new DateTimeImmutable('2026-08-26 12:00:00', new DateTimeZone('UTC')));

    expect($validator->weak)->toBeTrue()
        ->and($validator->header())->toBe('W/"abc123"')
        ->and($validator->lastModified?->format('Y-m-d H:i:s'))->toBe('2026-08-26 12:00:00');
});

it('leaves the entity tag untouched by the modification date', function (): void {
    $validator = new Validator('"abc123"', lastModified: new DateTimeImmutable('2026-08-26 12:00:00', new DateTimeZone('UTC')));

    expect($validator->etag)->toBe('abc123')
        ->and($validator->header())->toBe('"abc123"');
});
