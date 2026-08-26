<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\ConditionalRequests;
use ExpertSystems\ConditionalRequests\Contracts\LockableValidatorStrategy;
use ExpertSystems\ConditionalRequests\Contracts\ValidatorStrategy;
use ExpertSystems\ConditionalRequests\Exceptions\LockTimeoutException;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\ObservesTransactionLevel;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\SecondaryArticle;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Ticket;
use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

beforeEach(function (): void {
    // phpunit.xml.dist sets backupStaticProperties="false", so this survives
    // between tests in the same process unless it is cleared.
    ObservesTransactionLevel::$levels = [];

    Article::create(['title' => 'Hello', 'version' => 1]);
});

/**
 * The current tag for article 1, in the form a client would send it back.
 */
function lockModeTag(int $key = 1): string
{
    $article = Article::query()->findOrFail($key);

    return '"'.(string) $article->conditionalValidator(Request::create('/'))?->etag.'"';
}

it('runs the controller inside a transaction', function (): void {
    $level = null;

    Route::middleware([SubstituteBindings::class, 'conditional:required,lock'])
        ->put('/articles/{article}', function (Article $article) use (&$level): array {
            $level = DB::connection()->transactionLevel();

            return ['title' => $article->title];
        });

    $this->putJson('/articles/1', [], ['If-Match' => lockModeTag()])->assertOk();

    expect($level)->toBe(1);
});

it('does not put a transaction around a route that did not ask for one', function (): void {
    $level = null;

    Route::middleware([SubstituteBindings::class, 'conditional:required'])
        ->put('/articles/{article}', function (Article $article) use (&$level): array {
            $level = DB::connection()->transactionLevel();

            return ['title' => $article->title];
        });

    $this->putJson('/articles/1', [], ['If-Match' => lockModeTag()])->assertOk();

    expect($level)->toBe(0);
});

it('releases the lock before the response leaves the middleware', function (): void {
    // ObservesTransactionLevel sits outside `conditional`, so it reads the
    // depth on the way back out — after the middleware has returned but before
    // anything else can have closed a transaction on its behalf.
    Route::middleware([SubstituteBindings::class, ObservesTransactionLevel::class, 'conditional:required,lock'])
        ->put('/articles/{article}', fn (Article $article): array => ['title' => $article->title]);

    $this->putJson('/articles/1', [], ['If-Match' => lockModeTag()])->assertOk();

    expect(ObservesTransactionLevel::$levels)->toBe([0])
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('refuses a write whose row changed between the guard and the lock', function (): void {
    $ran = 0;
    $interleaved = false;

    // TransactionBeginning is the seam: the transaction is open and nothing has
    // been read on it yet, so this is the last moment at which a competing
    // commit is still invisible to the guard. Task 4 runs the same experiment
    // from a genuinely independent connection; here it is enough that the
    // re-read sees a row the first evaluation did not.
    Event::listen(TransactionBeginning::class, function () use (&$interleaved): void {
        if ($interleaved) {
            return;
        }

        $interleaved = true;

        Article::query()->whereKey(1)->update(['version' => 2]);
    });

    // The Article type hint is load-bearing, not decoration: implicit binding
    // only fires for a type-hinted signature parameter, and without it
    // ModelStrategy would see the raw string "1", produce no validator, and
    // this test would go green on a 412 raised by the *outer* evaluation.
    Route::middleware([SubstituteBindings::class, 'conditional:required,lock'])
        ->put('/articles/{article}', function (Article $article) use (&$ran): array {
            $ran++;

            return ['ok' => true];
        });

    $this->putJson('/articles/1', [], ['If-Match' => lockModeTag()])->assertStatus(412);

    expect($interleaved)->toBeTrue()
        ->and($ran)->toBe(0);
});

it('lets the same write through when nothing changed under the lock', function (): void {
    Route::middleware([SubstituteBindings::class, 'conditional:required,lock'])
        ->put('/articles/{article}', function (Article $article): array {
            $article->update(['title' => 'Updated', 'version' => 2]);

            return ['title' => $article->title];
        });

    $this->putJson('/articles/1', [], ['If-Match' => lockModeTag()])
        ->assertOk()
        ->assertJson(['title' => 'Updated']);

    expect(Article::query()->findOrFail(1)->title)->toBe('Updated');
});

it('rolls back what the transaction did when the lock refuses the write', function (): void {
    Event::listen(TransactionBeginning::class, function (): void {
        Article::query()->whereKey(1)->update(['title' => 'interleaved', 'version' => 2]);
    });

    Route::middleware([SubstituteBindings::class, 'conditional:required,lock'])
        ->put('/articles/{article}', fn (Article $article): array => ['ok' => true]);

    $this->putJson('/articles/1', [], ['If-Match' => lockModeTag()])->assertStatus(412);

    // The interleaved write shared our transaction, so the rollback took it
    // with it. What matters is that the rollback happened at all.
    expect(Article::query()->findOrFail(1)->title)->toBe('Hello')
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('hands the controller the freshly locked record, not the bound one', function (): void {
    $seen = null;

    Event::listen(TransactionBeginning::class, function (): void {
        Article::query()->whereKey(1)->update(['title' => 'moved on']);
    });

    Route::middleware([SubstituteBindings::class, 'conditional:lock'])
        ->put('/articles/{article}', function (Article $article) use (&$seen): array {
            $seen = $article->title;

            return ['title' => $article->title];
        });

    $this->putJson('/articles/1')->assertOk();

    expect($seen)->toBe('moved on');
});

it('locks on the records own connection, not the default one', function (): void {
    config()->set('database.connections.secondary', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]);

    Schema::connection('secondary')->create('articles', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->unsignedInteger('version')->nullable();
        $table->timestamps();
    });

    SecondaryArticle::create(['title' => 'Elsewhere', 'version' => 1]);

    $levels = [];

    Route::middleware([SubstituteBindings::class, 'conditional:required,lock'])
        ->put('/secondary/{secondary_article}', function (SecondaryArticle $secondaryArticle) use (&$levels): array {
            $levels = [
                'secondary' => DB::connection('secondary')->transactionLevel(),
                'default' => DB::connection()->transactionLevel(),
            ];

            return ['ok' => true];
        });

    $tag = '"'.(string) SecondaryArticle::query()->findOrFail(1)
        ->conditionalValidator(Request::create('/'))?->etag.'"';

    $this->putJson('/secondary/1', [], ['If-Match' => $tag])->assertOk();

    expect($levels)->toBe(['secondary' => 1, 'default' => 0]);
});

it('refuses to pretend it locked a resource that is not a row', function (): void {
    Route::bind('ticket', fn (): Ticket => new Ticket);

    Route::middleware([SubstituteBindings::class, 'conditional:lock'])
        ->put('/tickets/{ticket}', fn (): array => ['ok' => true]);

    $this->withoutExceptionHandling();

    expect(fn () => $this->putJson('/tickets/1'))
        ->toThrow(LogicException::class, 'is not an Eloquent model');
});

it('has nothing to lock on a create and says so by doing nothing', function (): void {
    $level = null;

    Route::middleware([SubstituteBindings::class, 'conditional:lock'])
        ->post('/articles', function () use (&$level): array {
            $level = DB::connection()->transactionLevel();

            return ['ok' => true];
        });

    $this->postJson('/articles', [], ['If-None-Match' => '*'])->assertOk();

    expect($level)->toBe(0);
});

it('refuses a lock flag on a strategy that cannot name a row', function (): void {
    app(ConditionalRequests::class)->extend('tagonly', fn (): ValidatorStrategy => fixedRequestTagStrategy('x'));

    Route::middleware([SubstituteBindings::class, 'conditional:tagonly,lock'])
        ->put('/articles/{article}', fn (): array => ['ok' => true]);

    $this->withoutExceptionHandling();

    expect(fn () => $this->putJson('/articles/1'))
        ->toThrow(LogicException::class, 'cannot name a row to lock');
});

it('reports the lock error first when a route is wrong in both ways', function (): void {
    Route::middleware([SubstituteBindings::class, 'conditional:body,required,lock'])
        ->put('/articles/{article}', fn (): array => ['ok' => true]);

    $this->withoutExceptionHandling();

    expect(fn () => $this->putJson('/articles/1'))
        ->toThrow(LogicException::class, 'cannot name a row to lock');
});

it('ignores the lock flag on a safe method', function (): void {
    $level = null;

    Route::middleware([SubstituteBindings::class, 'conditional:model,lock'])
        ->get('/articles/{article}', function (Article $article) use (&$level): array {
            $level = DB::connection()->transactionLevel();

            return ['title' => $article->title];
        });

    $this->getJson('/articles/1')->assertOk()->assertHeader('ETag', lockModeTag());

    expect($level)->toBe(0);
});

it('answers a lock it could not take with 503 rather than 500', function (): void {
    app(ConditionalRequests::class)->extend('contended', fn (): ValidatorStrategy => new class implements LockableValidatorStrategy
    {
        public function fromRequest(Request $request): ?Validator
        {
            return new Validator('current');
        }

        public function targetExists(Request $request): ?bool
        {
            return true;
        }

        public function fromResponse(Request $request, SymfonyResponse $response): ?Validator
        {
            return $this->fromRequest($request);
        }

        public function lockTarget(Request $request): ?Model
        {
            return Article::query()->findOrFail(1);
        }

        public function lockAndRefresh(Request $request, Model $target): ?Model
        {
            throw new QueryException(
                'testing',
                'select * from "articles" for update',
                [],
                new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'),
            );
        }
    });

    Route::middleware([SubstituteBindings::class, 'conditional:contended,lock'])
        ->put('/articles/{article}', fn (): array => ['ok' => true]);

    $this->putJson('/articles/1', [], ['If-Match' => '"current"'])
        ->assertStatus(503)
        ->assertHeader('Retry-After')
        ->assertJson(['message' => trans(LockTimeoutException::MESSAGE_KEY)]);
});

it('does not disguise an ordinary query failure as contention', function (): void {
    app(ConditionalRequests::class)->extend('broken', fn (): ValidatorStrategy => new class implements LockableValidatorStrategy
    {
        public function fromRequest(Request $request): ?Validator
        {
            return new Validator('current');
        }

        public function targetExists(Request $request): ?bool
        {
            return true;
        }

        public function fromResponse(Request $request, SymfonyResponse $response): ?Validator
        {
            return $this->fromRequest($request);
        }

        public function lockTarget(Request $request): ?Model
        {
            return Article::query()->findOrFail(1);
        }

        public function lockAndRefresh(Request $request, Model $target): ?Model
        {
            throw new QueryException('testing', 'select 1', [], new PDOException('no such table: articles'));
        }
    });

    Route::middleware([SubstituteBindings::class, 'conditional:broken,lock'])
        ->put('/articles/{article}', fn (): array => ['ok' => true]);

    $this->withoutExceptionHandling();

    expect(fn () => $this->putJson('/articles/1', [], ['If-Match' => '"current"']))
        ->toThrow(QueryException::class);
});

it('is suppressed by the master switch', function (): void {
    config()->set('laravel-conditional-requests.enabled', false);

    $level = null;

    Route::middleware([SubstituteBindings::class, 'conditional:required,lock'])
        ->put('/articles/{article}', function () use (&$level): array {
            $level = DB::connection()->transactionLevel();

            return ['ok' => true];
        });

    $this->putJson('/articles/1')->assertOk();

    expect($level)->toBe(0);
});

it('is suppressed by a uri exclusion', function (): void {
    config()->set('laravel-conditional-requests.exclude', ['articles/*']);

    $level = null;

    Route::middleware([SubstituteBindings::class, 'conditional:required,lock'])
        ->put('/articles/{article}', function () use (&$level): array {
            $level = DB::connection()->transactionLevel();

            return ['ok' => true];
        });

    $this->putJson('/articles/1')->assertOk();

    expect($level)->toBe(0);
});
