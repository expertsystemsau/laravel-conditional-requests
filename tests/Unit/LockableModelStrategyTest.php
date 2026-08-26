<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Contracts\LockableValidatorStrategy;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use ExpertSystems\ConditionalRequests\Validators\ModelStrategy;
use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * A request already routed to /articles/{article} with the record bound, which
 * is the state SubstituteBindings leaves behind and the only state in which
 * either of the new contract methods has anything to work with.
 */
function lockableBoundRequest(Article $article, string $method = 'PUT'): Request
{
    $request = Request::create('/articles/'.$article->getKey(), $method);

    $route = (new Route([$method], '/articles/{article}', fn (): string => 'ok'))
        ->bind($request);

    $route->setParameter('article', $article);
    $request->setRouteResolver(fn (): Route => $route);

    return $request;
}

beforeEach(function (): void {
    Article::create(['title' => 'Hello', 'version' => 1]);
});

it('is a lockable strategy', function (): void {
    expect(new ModelStrategy)->toBeInstanceOf(LockableValidatorStrategy::class);
});

it('names the bound record as the row to lock', function (): void {
    $article = Article::query()->findOrFail(1);

    expect((new ModelStrategy)->lockTarget(lockableBoundRequest($article)))->toBe($article);
});

it('has nothing to lock when nothing has been routed', function (): void {
    expect((new ModelStrategy)->lockTarget(Request::create('/articles/1', 'PUT')))->toBeNull();
});

it('has nothing to lock when the route binds no record', function (): void {
    $request = Request::create('/articles', 'POST');
    $route = (new Route(['POST'], '/articles', fn (): string => 'ok'))->bind($request);
    $request->setRouteResolver(fn (): Route => $route);

    expect((new ModelStrategy)->lockTarget($request))->toBeNull();
});

it('asks the database for a row lock', function (): void {
    // SQLiteGrammar::compileLock() returns an empty string, so the harness
    // connection can never show a FOR UPDATE clause. Compile the very same
    // query the implementation builds against the two grammars that do emit
    // one. toSql() opens no socket: the ConnectionFactory resolves PDO through
    // a closure, and both grammars are constructed from the driver name alone.
    config()->set('database.connections.mysql_probe', [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'probe',
        'username' => 'probe', 'password' => '', 'prefix' => '',
    ]);
    config()->set('database.connections.pgsql_probe', [
        'driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'probe',
        'username' => 'probe', 'password' => '', 'prefix' => '', 'schema' => 'public',
    ]);

    $article = Article::query()->findOrFail(1);

    foreach (['mysql_probe', 'pgsql_probe'] as $connection) {
        $sql = (new ModelStrategy)->lockingQuery((clone $article)->setConnection($connection))->toSql();

        expect(strtolower($sql))->toContain('for update');
    }
});

it('locks exactly one row, by key', function (): void {
    $sql = (new ModelStrategy)->lockingQuery(Article::query()->findOrFail(1))->toSql();

    expect(strtolower($sql))->toContain('"id" = ?');
});

it('re-reads the record and makes it the one fromRequest sees', function (): void {
    $strategy = new ModelStrategy;
    $article = Article::query()->findOrFail(1);
    $request = lockableBoundRequest($article);

    $before = $strategy->fromRequest($request);

    // A competing writer. The bound instance still holds version 1.
    Article::query()->whereKey(1)->update(['version' => 2]);

    $fresh = $strategy->lockAndRefresh($request, $article);

    expect($fresh?->getAttribute('version'))->toBe(2)
        ->and($strategy->fromRequest($request)?->etag)->not->toBe($before?->etag);
});

it('leaves the route holding the freshly locked instance, not the stale one', function (): void {
    $article = Article::query()->findOrFail(1);
    $request = lockableBoundRequest($article);

    $fresh = (new ModelStrategy)->lockAndRefresh($request, $article);

    expect($request->route()?->parameter('article'))->toBe($fresh)
        ->and($request->route()?->parameter('article'))->not->toBe($article);
});

it('forgets a record that was deleted before the lock was taken', function (): void {
    $strategy = new ModelStrategy;
    $article = Article::query()->findOrFail(1);
    $request = lockableBoundRequest($article);

    Article::query()->whereKey(1)->delete();

    expect($strategy->lockAndRefresh($request, $article))->toBeNull()
        ->and($request->route()?->parameter('article'))->toBeNull()
        ->and($strategy->fromRequest($request))->toBeNull();
});

it('leaves an unrouted request alone rather than erroring', function (): void {
    $article = Article::query()->findOrFail(1);

    expect((new ModelStrategy)->lockAndRefresh(Request::create('/articles/1', 'PUT'), $article))
        ->toBeInstanceOf(Article::class);
});

it('weakens a re-read validator exactly as it weakens a first one', function (): void {
    $strategy = new ModelStrategy(weak: true);
    $article = Article::query()->findOrFail(1);
    $request = lockableBoundRequest($article);

    $strategy->lockAndRefresh($request, $article);

    expect($strategy->fromRequest($request))->toBeInstanceOf(Validator::class)
        ->and($strategy->fromRequest($request)?->weak)->toBeTrue();
});
