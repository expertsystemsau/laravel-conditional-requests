<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Contracts\RequestValidatorStrategy;
use ExpertSystems\ConditionalRequests\Contracts\ValidatorStrategy;
use ExpertSystems\ConditionalRequests\Facades\ConditionalRequests;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Article::create(['title' => 'Hello', 'version' => 1]);
});

function writeRoute(string $middleware): void
{
    Route::middleware([SubstituteBindings::class, 'conditional:model'])
        ->get('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);

    Route::middleware([SubstituteBindings::class, $middleware])
        ->put('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);
}

it('refuses to guard a route whose strategy cannot answer before the controller', function (): void {
    $this->withoutExceptionHandling();

    writeRoute('conditional:body,required');

    $this->put('/articles/1');
})->throws(LogicException::class, 'the [body] validator strategy cannot produce a validator before the controller runs');

it('names the contract a strategy has to implement to be guardable', function (): void {
    $this->withoutExceptionHandling();

    writeRoute('conditional:body,required');

    $this->put('/articles/1');
})->throws(LogicException::class, RequestValidatorStrategy::class);

it('refuses to guard a route whose validators are weak', function (): void {
    $this->withoutExceptionHandling();

    config()->set('laravel-conditional-requests.weak', true);

    writeRoute('conditional:required');

    $this->put('/articles/1', [], ['If-Match' => '"anything"']);
})->throws(LogicException::class, '[laravel-conditional-requests.weak]');

it('explains why a weak validator cannot be guarded', function (): void {
    $this->withoutExceptionHandling();

    config()->set('laravel-conditional-requests.weak', true);

    writeRoute('conditional:required');

    $this->put('/articles/1');
})->throws(LogicException::class, 'RFC 9110 §13.1.1 requires strong comparison');

it('leaves a route without required alone when its strategy cannot answer', function (): void {
    writeRoute('conditional:body');

    $this->put('/articles/1')->assertOk();
});

it('leaves a route without required alone when its validators are weak', function (): void {
    config()->set('laravel-conditional-requests.weak', true);

    writeRoute('conditional:model');

    $this->put('/articles/1')->assertOk();
});

it('still refuses a weak validators If-Match, current tag and all', function (): void {
    // Not an error, because the route never asked for the guard — but it is
    // still 412, because §13.1.1 says a weak validator cannot satisfy If-Match.
    // This is the failure the required error exists to explain in advance.
    config()->set('laravel-conditional-requests.weak', true);

    writeRoute('conditional:model');

    $etag = (string) $this->get('/articles/1')->headers->get('ETag');

    expect($etag)->toStartWith('W/"');

    $this->put('/articles/1', [], ['If-Match' => $etag])->assertStatus(412);
});

it('guards a route whose custom strategy can answer before the controller', function (): void {
    ConditionalRequests::extend('probe-request', fn (): ValidatorStrategy => fixedRequestTagStrategy('custom-tag'));

    writeRoute('conditional:probe-request,required');

    $this->put('/articles/1')->assertStatus(428);
    $this->put('/articles/1', [], ['If-Match' => '"custom-tag"'])->assertOk();
});

it('still rejects an unknown strategy name on the write path', function (): void {
    $this->withoutExceptionHandling();

    writeRoute('conditional:nope,required');

    $this->put('/articles/1');
})->throws(InvalidArgumentException::class, 'Conditional request strategy [nope] is not registered.');
