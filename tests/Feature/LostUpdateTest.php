<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use ExpertSystems\ConditionalRequests\Validators\ModelStrategy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * The temp file both racing connections open.
 *
 * A static rather than a property on the test case: PHPUnit's TestCase does not
 * allow dynamic properties, and under `pest --parallel` each worker is its own
 * process, so a static is per-worker and tempnam() keeps it unique anyway.
 */
function racingDatabase(?string $path = null): string
{
    static $current = '';

    if ($path !== null) {
        $current = $path;
    }

    return $current;
}

/**
 * Arm a one-shot competing commit before the second query on the guarded
 * connection.
 *
 * The first query is always SubstituteBindings' binding select. The second is
 * the controller's UPDATE on an unlocked route and the locked re-read on a
 * locked one — the same logical moment in both, which is what makes the two
 * experiments comparable. It must also be before the guarded connection reads
 * anything inside its transaction: once it holds a SHARED lock on the file,
 * the competitor's write cannot commit.
 */
function interleaveCompetingCommit(): Closure
{
    $fired = false;
    $seen = 0;

    DB::connection('racing')->beforeExecuting(function () use (&$fired, &$seen): void {
        $seen++;

        if ($seen !== 2 || $fired) {
            return;
        }

        $fired = true;

        DB::connection('competitor')->table('articles')->where('id', 1)->update([
            'title' => 'competitor',
            'version' => 2,
        ]);
    });

    return function () use (&$fired): bool {
        return $fired;
    };
}

function racingTag(): string
{
    $article = Article::query()->findOrFail(1);

    return '"'.(string) $article->conditionalValidator(Request::create('/'))?->etag.'"';
}

function racingRoute(string $middleware): void
{
    Route::middleware([SubstituteBindings::class, $middleware])
        ->put('/articles/{article}', function (Article $article): array {
            $article->update([
                'title' => 'client',
                'version' => (int) $article->version + 1,
            ]);

            return ['title' => $article->title];
        });
}

beforeEach(function (): void {
    // A file, not :memory:. Two connections to :memory: are two separate
    // databases, so there would be nobody to race against.
    // tempnam() creates the file, so there is nothing to touch and no
    // suffix to append — sqlite does not care what a database is called.
    racingDatabase(tempnam(sys_get_temp_dir(), 'conditional-requests-'));

    foreach (['racing', 'competitor'] as $name) {
        config()->set("database.connections.{$name}", [
            'driver' => 'sqlite',
            'database' => racingDatabase(),
            'prefix' => '',
            'busy_timeout' => 2000,
        ]);
    }

    config()->set('database.default', 'racing');

    Schema::connection('racing')->create('articles', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->unsignedInteger('version')->nullable();
        $table->timestamps();
    });

    Article::create(['title' => 'original', 'version' => 1]);
});

afterEach(function (): void {
    DB::purge('racing');
    DB::purge('competitor');

    if (racingDatabase() !== '' && file_exists(racingDatabase())) {
        unlink(racingDatabase());
    }
});

it('loses an update without the lock', function (): void {
    racingRoute('conditional:required');

    $tag = racingTag();
    $fired = interleaveCompetingCommit();

    $this->putJson('/articles/1', [], ['If-Match' => $tag])->assertOk();

    expect($fired())->toBeTrue()
        // The competitor committed a change this request never saw, and this
        // request wrote over it anyway. That is the lost update, and it is the
        // behaviour v0.3 ships and documents.
        ->and(Article::query()->findOrFail(1)->title)->toBe('client');
});

it('cannot lose that update with the lock', function (): void {
    racingRoute('conditional:required,lock');

    $tag = racingTag();
    $fired = interleaveCompetingCommit();

    $this->putJson('/articles/1', [], ['If-Match' => $tag])->assertStatus(412);

    expect($fired())->toBeTrue()
        // Committed on an independent connection, so our rollback did not take
        // it with it. Nothing was lost.
        ->and(Article::query()->findOrFail(1)->title)->toBe('competitor')
        ->and((int) Article::query()->findOrFail(1)->version)->toBe(2);
});

it('runs the same write to completion when nobody is competing', function (): void {
    racingRoute('conditional:required,lock');

    $this->putJson('/articles/1', [], ['If-Match' => racingTag()])
        ->assertOk()
        ->assertJson(['title' => 'client']);

    expect(Article::query()->findOrFail(1)->title)->toBe('client');
});

it('cannot see a row lock on this harness, and does not pretend to', function (): void {
    // SQLiteGrammar::compileLock() returns an empty string. Everything above
    // proves the re-evaluation; nothing above proves mutual exclusion, and
    // nothing here ever can. tests/Feature/RowLockContentionTest.php proves it
    // on the drivers that have row locks.
    $sql = (new ModelStrategy)->lockingQuery(Article::query()->findOrFail(1))->toSql();

    expect(strtolower($sql))->not->toContain('for update');
});
