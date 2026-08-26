<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Exceptions\LockTimeoutException;
use ExpertSystems\ConditionalRequests\Tests\Fixtures\Article;
use ExpertSystems\ConditionalRequests\Validators\ModelStrategy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * env() is banned project-wide by tests/ArchTest.php, so the gate reads the
 * environment directly.
 */
function lockDriver(): string
{
    $driver = getenv('CONDITIONAL_LOCK_DRIVER');

    return is_string($driver) ? $driver : '';
}

function lockDriverMissing(): bool
{
    return ! in_array(lockDriver(), ['mysql', 'pgsql'], strict: true);
}

function lockSkipMessage(): string
{
    return 'Row locks need a real database. Set CONDITIONAL_LOCK_DRIVER=mysql|pgsql plus '
        .'CONDITIONAL_LOCK_HOST, _PORT, _DATABASE, _USERNAME and _PASSWORD, or run `composer test:lock`.';
}

function lockConnectionConfig(): array
{
    $port = getenv('CONDITIONAL_LOCK_PORT');
    $host = getenv('CONDITIONAL_LOCK_HOST');
    $database = getenv('CONDITIONAL_LOCK_DATABASE');
    $username = getenv('CONDITIONAL_LOCK_USERNAME');
    $password = getenv('CONDITIONAL_LOCK_PASSWORD');

    return array_filter([
        'driver' => lockDriver(),
        'host' => is_string($host) ? $host : '127.0.0.1',
        'port' => is_string($port) ? $port : (lockDriver() === 'pgsql' ? '5432' : '3306'),
        'database' => is_string($database) ? $database : 'conditional_requests',
        'username' => is_string($username) ? $username : 'root',
        'password' => is_string($password) ? $password : '',
        'prefix' => '',
        'charset' => lockDriver() === 'pgsql' ? 'utf8' : 'utf8mb4',
        'search_path' => lockDriver() === 'pgsql' ? 'public' : null,
    ], fn (mixed $value): bool => $value !== null);
}

function contendedTag(): string
{
    return '"'.(string) Article::query()->findOrFail(1)
        ->conditionalValidator(Request::create('/'))?->etag.'"';
}

beforeEach(function (): void {
    if (lockDriverMissing()) {
        return;
    }

    foreach (['contended', 'competitor'] as $name) {
        config()->set("database.connections.{$name}", lockConnectionConfig());
    }

    config()->set('database.default', 'contended');
    config()->set('laravel-conditional-requests.lock_timeout', 1);

    Schema::connection('contended')->dropIfExists('articles');
    Schema::connection('contended')->create('articles', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->unsignedInteger('version')->nullable();
        $table->timestamps();
    });

    Article::create(['title' => 'original', 'version' => 1]);

    Route::middleware([SubstituteBindings::class, 'conditional:required,lock'])
        ->put('/articles/{article}', function (Article $article): array {
            $article->update(['title' => 'client', 'version' => (int) $article->version + 1]);

            return ['title' => $article->title];
        });
});

afterEach(function (): void {
    if (lockDriverMissing()) {
        return;
    }

    if (DB::connection('competitor')->transactionLevel() > 0) {
        DB::connection('competitor')->rollBack();
    }

    Schema::connection('contended')->dropIfExists('articles');

    DB::purge('contended');
    DB::purge('competitor');
});

it('really asks for a row lock', function (): void {
    $seen = [];

    DB::connection('contended')->listen(function ($query) use (&$seen): void {
        $seen[] = strtolower($query->sql);
    });

    $this->putJson('/articles/1', [], ['If-Match' => contendedTag()])->assertOk();

    $locking = array_filter($seen, fn (string $sql): bool => str_contains($sql, 'for update'));

    expect($locking)->not->toBe([]);
})->skip(fn (): bool => lockDriverMissing(), lockSkipMessage());

it('cannot take a row another session is holding', function (): void {
    // A second, independent session — two connections is what a row lock is
    // held by, and one process is enough to have two of them.
    DB::connection('competitor')->beginTransaction();
    DB::connection('competitor')->table('articles')->where('id', 1)->lockForUpdate()->first();

    $response = $this->putJson('/articles/1', [], ['If-Match' => contendedTag()]);

    DB::connection('competitor')->rollBack();

    $response->assertStatus(503)
        ->assertHeader('Retry-After')
        ->assertJson(['message' => trans(LockTimeoutException::MESSAGE_KEY)]);

    expect(Article::query()->findOrFail(1)->title)->toBe('original');
})->skip(fn (): bool => lockDriverMissing(), lockSkipMessage());

it('takes the same row the moment it is free', function (): void {
    // The control. Without it the test above only proves the route can 503.
    DB::connection('competitor')->beginTransaction();
    DB::connection('competitor')->table('articles')->where('id', 1)->lockForUpdate()->first();
    DB::connection('competitor')->rollBack();

    $this->putJson('/articles/1', [], ['If-Match' => contendedTag()])
        ->assertOk()
        ->assertJson(['title' => 'client']);
})->skip(fn (): bool => lockDriverMissing(), lockSkipMessage());

it('puts the session back the way it found it', function (): void {
    // MySQL's innodb_lock_wait_timeout is session-scoped, so LockWait restores
    // it. Under Octane or a persistent PDO a leak here would silently apply to
    // every later query on the connection.
    $before = lockDriver() === 'pgsql'
        ? DB::connection('contended')->selectOne("select current_setting('lock_timeout') as value")
        : DB::connection('contended')->selectOne('select @@session.innodb_lock_wait_timeout as value');

    $this->putJson('/articles/1', [], ['If-Match' => contendedTag()])->assertOk();

    $after = lockDriver() === 'pgsql'
        ? DB::connection('contended')->selectOne("select current_setting('lock_timeout') as value")
        : DB::connection('contended')->selectOne('select @@session.innodb_lock_wait_timeout as value');

    expect($after?->value)->toBe($before?->value);
})->skip(fn (): bool => lockDriverMissing(), lockSkipMessage());

it('compiles the lock for this driver', function (): void {
    $sql = (new ModelStrategy)->lockingQuery(Article::query()->findOrFail(1))->toSql();

    expect(strtolower($sql))->toContain('for update');
})->skip(fn (): bool => lockDriverMissing(), lockSkipMessage());
