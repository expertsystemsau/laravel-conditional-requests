<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Validators\BodyHashStrategy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

it('hashes the response body', function (): void {
    $validator = (new BodyHashStrategy)->fromResponse(
        Request::create('/articles'),
        new Response('hello world'),
    );

    expect($validator?->etag)->toBe(hash('xxh128', 'hello world'));
});

it('produces the same tag for identical bodies', function (): void {
    $strategy = new BodyHashStrategy;
    $request = Request::create('/articles');

    expect($strategy->fromResponse($request, new Response('same'))?->etag)
        ->toBe($strategy->fromResponse($request, new Response('same'))?->etag);
});

it('produces different tags for different bodies', function (): void {
    $strategy = new BodyHashStrategy;
    $request = Request::create('/articles');

    expect($strategy->fromResponse($request, new Response('one'))?->etag)
        ->not->toBe($strategy->fromResponse($request, new Response('two'))?->etag);
});

it('honours a different hash algorithm', function (): void {
    $validator = (new BodyHashStrategy('sha256'))->fromResponse(
        Request::create('/articles'),
        new Response('hello world'),
    );

    expect($validator?->etag)->toBe(hash('sha256', 'hello world'));
});

it('marks the validator weak when configured to', function (): void {
    $validator = (new BodyHashStrategy(weak: true))->fromResponse(
        Request::create('/articles'),
        new Response('hello world'),
    );

    expect($validator?->weak)->toBeTrue();
});

it('declines to produce a validator for an empty body', function (): void {
    $validator = (new BodyHashStrategy)->fromResponse(
        Request::create('/articles'),
        new Response(''),
    );

    expect($validator)->toBeNull();
});
