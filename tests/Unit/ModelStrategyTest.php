<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\ConditionalRequests;
use ExpertSystems\ConditionalRequests\Contracts\ProvidesConditionalValidator;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Note;
use ExpertSystems\ConditionalRequests\Validators\ModelStrategy;
use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
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

// --- target existence, the write path's create guard ---

it('reports a bound record as present', function (): void {
    $request = routedRequest('/articles/1', '/articles/{article}', ['article' => fixtureArticleFor(1)]);

    expect((new ModelStrategy)->targetExists($request))->toBeTrue();
});

it('reports a bound record that yields no validator as present', function (): void {
    // The distinction fromRequest() cannot express: the row is there, it just
    // cannot state a version. Reading it as absent let the create guard write.
    $request = routedRequest('/articles/1', '/articles/{article}', ['article' => new Article]);
    $strategy = new ModelStrategy;

    expect($strategy->fromRequest($request))->toBeNull()
        ->and($strategy->targetExists($request))->toBeTrue();
});

it('reports a parameter a binder answered null for as absent', function (): void {
    $request = routedRequest('/articles/999', '/articles/{article}', ['article' => null]);

    expect((new ModelStrategy)->targetExists($request))->toBeFalse();
});

it('reports a collection route as addressing no record', function (): void {
    // POST /articles: bound, no parameters, nothing for a create to collide
    // with. The README documents the create guard passing here.
    $request = routedRequest('/articles', '/articles');

    expect((new ModelStrategy)->targetExists($request))->toBeFalse();
});

it('cannot tell whether an unsubstituted parameter exists', function (): void {
    // Conditional declared ahead of SubstituteBindings. The parameter is still
    // the raw URI segment, so nothing is known — and "cannot tell" has to fail
    // the create guard closed rather than read as absent.
    $request = routedRequest('/articles/1', '/articles/{article}');

    expect((new ModelStrategy)->targetExists($request))->toBeNull();
});

it('cannot tell whether a target exists before anything has been routed', function (): void {
    expect((new ModelStrategy)->targetExists(Request::create('/articles/1')))->toBeNull();
});

it('cannot tell whether a target exists on an unbound route', function (): void {
    $request = routedRequest('/articles/1', '/articles/{article}', bind: false);

    expect((new ModelStrategy)->targetExists($request))->toBeNull();
});

it('refuses to answer for a route binding more than one conditional record', function (): void {
    $request = routedRequest('/articles/1/notes/9', '/articles/{article}/notes/{note}', [
        'article' => fixtureArticleFor(1),
        'note' => (new Note)->forceFill(['id' => 9, 'body' => 'Nested', 'updated_at' => '2026-08-25 10:00:00']),
    ]);

    expect(fn () => (new ModelStrategy)->targetExists($request))->toThrow(function (LogicException $e): void {
        expect($e->getMessage())
            ->toContain('articles/{article}/notes/{note}')
            ->toContain('[article, note]')
            ->toContain(ProvidesConditionalValidator::class);
    });
});

it('leaves the read path first wins rule alone on the same route', function (): void {
    // targetExists() is the write path's question and the only place the
    // ambiguity is fatal. fromRequest() still answers, still first-wins.
    $article = fixtureArticleFor(1);

    $request = routedRequest('/articles/1/notes/9', '/articles/{article}/notes/{note}', [
        'article' => $article,
        'note' => (new Note)->forceFill(['id' => 9, 'body' => 'Nested', 'updated_at' => '2026-08-25 10:00:00']),
    ]);

    expect((new ModelStrategy)->fromRequest($request)?->etag)
        ->toBe($article->conditionalValidator($request)?->etag);
});

/**
 * The existing fixtureArticleFor() helper pins updated_at to a fixed past
 * instant, so the clock has to be parked after it for a date to be published
 * at all — see the one-second rule in HasConditionalValidator.
 */
function afterTheArticleWasWritten(): void
{
    test()->travelTo(Carbon::parse('2026-08-25 10:00:05', 'UTC'));
}

it('carries the models modification date through to the validator', function (): void {
    afterTheArticleWasWritten();

    $validator = (new ModelStrategy)->fromRequest(
        routedRequest('/articles/1', 'articles/{article}', ['article' => fixtureArticleFor(1)]),
    );

    expect($validator?->lastModified?->format('Y-m-d H:i:s'))->toBe('2026-08-25 10:00:00');
});

it('keeps the modification date when it weakens a tag', function (): void {
    // The rebuild that applies `weak` must not drop the date on the way past.
    afterTheArticleWasWritten();

    $validator = (new ModelStrategy(weak: true))->fromRequest(
        routedRequest('/articles/1', 'articles/{article}', ['article' => fixtureArticleFor(1)]),
    );

    expect($validator?->weak)->toBeTrue()
        ->and($validator?->lastModified?->format('Y-m-d H:i:s'))->toBe('2026-08-25 10:00:00');
});

it('drops the modification date when the strategy is told not to publish one', function (): void {
    afterTheArticleWasWritten();

    $validator = (new ModelStrategy(lastModified: false))->fromRequest(
        routedRequest('/articles/1', 'articles/{article}', ['article' => fixtureArticleFor(1)]),
    );

    expect($validator?->etag)->not->toBeNull()
        ->and($validator?->lastModified)->toBeNull();
});

it('publishes modification dates by default', function (): void {
    expect(config('laravel-conditional-requests.last_modified'))->toBeTrue();
});

it('builds the model strategy from the last_modified config key', function (): void {
    afterTheArticleWasWritten();

    config()->set('laravel-conditional-requests.last_modified', false);

    $strategy = app(ConditionalRequests::class)->strategy('model');
    $request = routedRequest('/articles/1', 'articles/{article}', ['article' => fixtureArticleFor(1)]);

    expect($strategy)->toBeInstanceOf(ModelStrategy::class)
        ->and($strategy->fromResponse($request, new Response)?->lastModified)->toBeNull();
});
