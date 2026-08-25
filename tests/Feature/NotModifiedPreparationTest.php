<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Contracts\RequestValidatorStrategy;
use ExpertSystems\ConditionalRequests\Facades\ConditionalRequests;
use ExpertSystems\ConditionalRequests\Http\Middleware\Conditional;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;

/**
 * A 304 has to be complete wherever the middleware sits.
 *
 * Symfony's Response::prepare() nulls the body of an empty response, strips
 * Content-Type and Content-Length, and clears PHP's `default_mimetype` — the
 * last of which is what stops the SAPI adding a Content-Type of its own to a
 * bodiless response. Under route or group placement Router::prepareResponse()
 * runs after the middleware and does all of that for free; under kernel-global
 * placement nothing re-prepares, and the middleware has to.
 *
 * These assert on the ini setting because that is where the behaviour lives:
 * the header is added by the SAPI at send time and never appears on the
 * Response object, so there is nothing on the response itself to look at. What
 * makes it worth guarding is RFC 9111 §4.3.4 — a cache updates its stored
 * response's headers from the 304, so a leaked `text/html; charset=UTF-8`
 * overwrites the `application/json` a client had stored, on the first
 * successful revalidation.
 */
beforeEach(function (): void {
    ini_set('default_mimetype', 'text/html');
});

afterEach(function (): void {
    ini_set('default_mimetype', 'text/html');
});

it('clears the default mimetype on a 304 decided after the controller ran', function (): void {
    app(Kernel::class)->pushMiddleware(Conditional::class);

    Route::get('/articles', fn (): array => ['title' => 'Hello']);

    $etag = $this->get('/articles')->headers->get('ETag');

    // The 200 above went out through prepare()'s other branch, which leaves the
    // setting alone; reset anyway so the assertion can only be answered by the
    // request under test.
    ini_set('default_mimetype', 'text/html');

    expect($this->get('/articles', ['If-None-Match' => $etag])->status())->toBe(304)
        ->and(ini_get('default_mimetype'))->toBe('');
});

it('clears the default mimetype on a 304 decided before the controller ran', function (): void {
    // A request-derived strategy that does not need a resolved route is the
    // only way to reach the short-circuit from outside the router, which is
    // where the leak lives — `model` finds no record out here and declines.
    ConditionalRequests::extend('fixed', fn (): RequestValidatorStrategy => fixedRequestTagStrategy('fixed-tag'));
    config()->set('laravel-conditional-requests.strategy', 'fixed');

    app(Kernel::class)->pushMiddleware(Conditional::class);

    $runs = 0;

    Route::get('/articles', function () use (&$runs): array {
        $runs++;

        return ['title' => 'Hello'];
    });

    $response = $this->get('/articles', ['If-None-Match' => '"fixed-tag"']);

    expect($response->status())->toBe(304)
        ->and($runs)->toBe(0)
        ->and(ini_get('default_mimetype'))->toBe('');
});
