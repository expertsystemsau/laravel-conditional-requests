<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\RecordsTransactionLevel;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    // phpunit.xml.dist sets backupStaticProperties="false", so this survives
    // between tests in the same process unless it is cleared.
    RecordsTransactionLevel::$levels = [];

    Article::create(['title' => 'Hello', 'version' => 1]);
});

function ownershipTag(): string
{
    return '"'.(string) Article::query()->findOrFail(1)
        ->conditionalValidator(Request::create('/'))?->etag.'"';
}

function ownershipRoute(Closure $action): void
{
    Route::middleware([SubstituteBindings::class, 'conditional:required,lock'])
        ->put('/articles/{article}', $action);
}

it('savepoints a transaction the controller opens for itself', function (): void {
    $inner = null;

    ownershipRoute(function (Article $article) use (&$inner): array {
        DB::transaction(function () use ($article, &$inner): void {
            $inner = DB::connection()->transactionLevel();

            $article->update(['title' => 'nested']);
        });

        return ['ok' => true];
    });

    $this->putJson('/articles/1', [], ['If-Match' => ownershipTag()])->assertOk();

    expect($inner)->toBe(2)
        ->and(Article::query()->findOrFail(1)->title)->toBe('nested')
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('lets the controller roll its own transaction back without losing ours', function (): void {
    ownershipRoute(function (Article $article): array {
        $article->update(['title' => 'kept']);

        try {
            DB::transaction(function () use ($article): void {
                $article->update(['title' => 'discarded']);

                throw new RuntimeException('inner');
            });
        } catch (RuntimeException) {
            // Rolled back to the savepoint; the outer transaction is still open.
        }

        return ['ok' => true];
    });

    $this->putJson('/articles/1', [], ['If-Match' => ownershipTag()])->assertOk();

    expect(Article::query()->findOrFail(1)->title)->toBe('kept');
});

it('runs a job dispatched in the controller before the transaction commits', function (): void {
    // §5.5's queued-jobs hazard, demonstrated rather than asserted in prose.
    // The job sees an open transaction, which on a real queue driver means the
    // worker can pick the job up before the row it is about to read exists.
    ownershipRoute(function (Article $article): array {
        RecordsTransactionLevel::dispatch();

        return ['ok' => true];
    });

    $this->putJson('/articles/1', [], ['If-Match' => ownershipTag()])->assertOk();

    expect(RecordsTransactionLevel::$levels)->toBe([1]);
});

it('honours afterCommit, which is the only fix for it', function (): void {
    ownershipRoute(function (Article $article): array {
        RecordsTransactionLevel::dispatch()->afterCommit();

        return ['ok' => true];
    });

    $this->putJson('/articles/1', [], ['If-Match' => ownershipTag()])->assertOk();

    expect(RecordsTransactionLevel::$levels)->toBe([0]);
});

it('holds the row lock for the whole of the controller, which is the cost', function (): void {
    $levels = [];

    ownershipRoute(function (Article $article) use (&$levels): array {
        $levels[] = DB::connection()->transactionLevel();

        $article->update(['title' => 'slow']);

        $levels[] = DB::connection()->transactionLevel();

        return ['ok' => true];
    });

    $this->putJson('/articles/1', [], ['If-Match' => ownershipTag()])->assertOk();

    expect($levels)->toBe([1, 1]);
});

it('commits an error response, because only an exception rolls back', function (): void {
    // Documented, not a defect: Connection::transaction() inspects control
    // flow, not status codes, and a hand-written DB::transaction() behaves the
    // same way. A controller that wants its work discarded must throw.
    ownershipRoute(function (Article $article) {
        $article->update(['title' => 'written anyway']);

        return response()->json(['message' => 'nope'], 500);
    });

    $this->putJson('/articles/1', [], ['If-Match' => ownershipTag()])->assertStatus(500);

    expect(Article::query()->findOrFail(1)->title)->toBe('written anyway');
});

it('rolls back and surfaces an exception from the controller unchanged', function (): void {
    ownershipRoute(function (Article $article): array {
        $article->update(['title' => 'discarded']);

        throw new RuntimeException('controller blew up');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->putJson('/articles/1', [], ['If-Match' => ownershipTag()]))
        ->toThrow(RuntimeException::class, 'controller blew up');

    expect(Article::query()->findOrFail(1)->title)->toBe('Hello')
        ->and(DB::connection()->transactionLevel())->toBe(0);
});
