<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Contracts\ProvidesConditionalValidator;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Note;
use ExpertSystems\ConditionalRequests\Validators\ModelStrategy;
use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request that has already been routed, with its parameters substituted —
 * the state SubstituteBindings leaves behind, built by hand so these stay unit
 * tests with no kernel and no database in them.
 *
 * @param  array<string, mixed>  $parameters
 */
function routedRequest(string $uri, string $pattern, array $parameters = [], bool $bind = true): Request
{
    $request = Request::create($uri);

    $route = new Route(['GET'], $pattern, fn (): null => null);

    if ($bind) {
        $route->bind($request);

        foreach ($parameters as $name => $value) {
            $route->setParameter($name, $value);
        }
    }

    $request->setRouteResolver(fn (): Route => $route);

    return $request;
}

function fixtureArticleFor(int $id): Article
{
    return (new Article)->forceFill(['id' => $id, 'title' => 'Hello', 'updated_at' => '2026-08-25 10:00:00']);
}

it('derives the validator from the bound model', function (): void {
    $article = fixtureArticleFor(1);
    $request = routedRequest('/articles/1', '/articles/{article}', ['article' => $article]);

    expect((new ModelStrategy)->fromRequest($request)?->etag)
        ->not->toBeNull()
        ->toBe($article->conditionalValidator($request)?->etag);
});

it('returns the same validator from fromResponse', function (): void {
    $request = routedRequest('/articles/1', '/articles/{article}', ['article' => fixtureArticleFor(1)]);
    $strategy = new ModelStrategy;

    expect($strategy->fromResponse($request, new Response('rendered body'))?->etag)
        ->not->toBeNull()
        ->toBe($strategy->fromRequest($request)?->etag);
});

it('takes the first route parameter implementing the contract', function (): void {
    $article = fixtureArticleFor(1);
    $note = (new Note)->forceFill(['id' => 9, 'body' => 'Nested', 'updated_at' => '2026-08-25 10:00:00']);

    $request = routedRequest('/articles/1/notes/9', '/articles/{article}/notes/{note}', [
        'article' => $article,
        'note' => $note,
    ]);

    expect((new ModelStrategy)->fromRequest($request)?->etag)
        ->not->toBeNull()
        ->toBe($article->conditionalValidator($request)?->etag);
});

it('passes the request to the model so a representation can vary the tag', function (): void {
    $aware = new class implements ProvidesConditionalValidator
    {
        public function conditionalValidator(Request $request): ?Validator
        {
            return new Validator('fields-'.(string) $request->query('fields'));
        }
    };

    $request = routedRequest('/articles/1?fields=title', '/articles/{article}', ['article' => $aware]);

    expect((new ModelStrategy)->fromRequest($request)?->etag)->toBe('fields-title');
});

it('returns null when the route parameter is still an unsubstituted string', function (): void {
    // Conditional placed ahead of SubstituteBindings: the route is there, the
    // parameter is not a model yet, and nothing can be derived from it.
    $request = routedRequest('/articles/1', '/articles/{article}');

    expect((new ModelStrategy)->fromRequest($request))->toBeNull();
});

it('returns null when nothing has been routed yet', function (): void {
    // Kernel-global placement: the middleware runs before the router.
    expect((new ModelStrategy)->fromRequest(Request::create('/articles/1')))->toBeNull();
});

it('returns null when the route has not been bound', function (): void {
    // Route::parameters() throws LogicException on an unbound route, so the
    // strategy has to ask before it reads.
    $request = routedRequest('/articles/1', '/articles/{article}', bind: false);

    expect((new ModelStrategy)->fromRequest($request))->toBeNull();
});

it('returns null when the model declines to produce a validator', function (): void {
    $request = routedRequest('/articles/1', '/articles/{article}', ['article' => new Article]);

    expect((new ModelStrategy)->fromRequest($request))->toBeNull();
});

it('marks the validator weak when configured to', function (): void {
    $request = routedRequest('/articles/1', '/articles/{article}', ['article' => fixtureArticleFor(1)]);

    $strong = (new ModelStrategy)->fromRequest($request);
    $weak = (new ModelStrategy(weak: true))->fromRequest($request);

    expect($weak?->weak)->toBeTrue()
        ->and($weak?->etag)->toBe($strong?->etag);
});
