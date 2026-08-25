<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\ConditionalRequests;
use ExpertSystems\ConditionalRequests\Contracts\ValidatorStrategy;
use ExpertSystems\ConditionalRequests\Validators\BodyHashStrategy;
use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

it('registers the body strategy out of the box', function (): void {
    expect(app(ConditionalRequests::class)->strategy('body'))
        ->toBeInstanceOf(BodyHashStrategy::class);
});

it('builds the body strategy from configuration', function (): void {
    config()->set('laravel-conditional-requests.hash', 'sha256');
    config()->set('laravel-conditional-requests.weak', true);

    $validator = app(ConditionalRequests::class)
        ->strategy('body')
        ->fromResponse(Request::create('/articles'), new Response('hello world'));

    expect($validator?->etag)->toBe(hash('sha256', 'hello world'))
        ->and($validator?->weak)->toBeTrue();
});

it('accepts a custom strategy', function (): void {
    app(ConditionalRequests::class)->extend('fixed', fn (): ValidatorStrategy => new class implements ValidatorStrategy
    {
        public function fromResponse(Request $request, Response $response): ?Validator
        {
            return new Validator('fixed-tag');
        }
    });

    $validator = app(ConditionalRequests::class)
        ->strategy('fixed')
        ->fromResponse(Request::create('/articles'), new Response('anything'));

    expect($validator?->etag)->toBe('fixed-tag');
});

it('rejects an unknown strategy by name', function (): void {
    app(ConditionalRequests::class)->strategy('nope');
})->throws(InvalidArgumentException::class, 'Conditional request strategy [nope] is not registered. Registered: body');
