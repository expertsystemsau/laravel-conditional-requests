<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\ConditionalRequests;
use ExpertSystems\ConditionalRequests\Contracts\ValidatorStrategy;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use ExpertSystems\ConditionalRequests\Validators\BodyHashStrategy;
use ExpertSystems\ConditionalRequests\Validators\ModelStrategy;
use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
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

it('registers the model strategy out of the box', function (): void {
    expect(app(ConditionalRequests::class)->strategy('model'))
        ->toBeInstanceOf(ModelStrategy::class);
});

it('builds the model strategy from configuration', function (): void {
    config()->set('laravel-conditional-requests.weak', true);

    $article = (new Article)->forceFill(['id' => 1, 'updated_at' => '2026-08-25 10:00:00']);

    Route::middleware([SubstituteBindings::class, 'conditional:model'])
        ->get('/articles/{article}', fn (): array => ['title' => 'Hello']);

    Route::bind('article', fn (): Article => $article);

    expect($this->get('/articles/1')->headers->get('ETag'))->toStartWith('W/"');
});

it('rejects an unknown strategy by name', function (): void {
    // The message must name the strategy that was asked for and point at what
    // is available, without this test pinning the full registered list — a
    // fourth built-in strategy is a feature, not a reason for this to fail.
    // tests/Feature/CustomStrategyTest.php makes the same promise the same way.
    expect(fn () => app(ConditionalRequests::class)->strategy('nope'))
        ->toThrow(function (InvalidArgumentException $e): void {
            expect($e->getMessage())
                ->toContain('[nope] is not registered')
                ->toContain('Registered:')
                ->toContain('body');
        });
});
