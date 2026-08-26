# Conditional writes

## The short version

`If-Match` is how a client states which version of a resource it believes it is modifying, so a write that would clobber someone else's is refused with `412` instead of applied. The guard covers **every** unsafe method — `POST`, `PUT`, `PATCH`, `DELETE` — and not just `PATCH`, because MDN's canonical mid-air-collision example is a wiki save over `POST`. The `required` flag is what stops a client opting out of the whole thing by omitting the header: a write carrying no precondition is answered `428 Precondition Required`. And the check-then-write race between the guard and your controller is real, small, and closed by the [`lock`](#closing-the-race--lock) flag and nothing else.

## Requiring a precondition — `428`

Require the client to state which version it believes it is modifying. A stale token is rejected with `412`; a missing one with `428`.

```php
Route::patch('/articles/{article}', UpdateArticle::class)
    ->middleware('conditional:required');
```

```http
PATCH /articles/42
If-Match: "9b1c0e0f6b0a4f9d3e7a2c81f4d6b059"
→ 200 OK                    # still current, write applied

PATCH /articles/42
If-Match: "9b1c0e0f6b0a4f9d3e7a2c81f4d6b059"
→ 412 Precondition Failed   # someone else got there first

PATCH /articles/42
                            # no If-Match header at all
→ 428 Precondition Required
```

`428` is the piece most implementations skip. Without it a client can simply omit the header and go straight back to clobbering other people's writes — the protection is opt-out by default. `conditional:required` makes it opt-in-by-force for the routes you choose.

The guard applies to **every** unsafe method — `POST`, `PUT`, `PATCH`, and `DELETE`. MDN's canonical mid-air-collision example is a wiki save over `POST`, so restricting it to `PATCH` would miss the documented case.

`If-Match` uses **strong** comparison, as RFC 9110 §13.1.1 requires: a `W/`-prefixed token never satisfies it, and neither does a weak validator on the server side. That includes `W/*`, which is not a weak wildcard but a malformed field value — the wildcard is a grammar alternative to the tag list rather than an entity tag, so there is nothing for the prefix to attach to, and a malformed `If-Match` fails closed with `412`. A blank `If-Match` is the same state: the header is present and names zero valid members, so it is `412` rather than treated as absent. On `If-None-Match` the same `W/*` token *is* read as the wildcard, matching Symfony, because there that is the fail-closed reading; the two headers differ because the safe answer differs. None of this is configurable — see the caveats below.

## Guarding a create — `If-None-Match: *`

`If-None-Match: *` is the mirror image: it succeeds only when the resource does **not** already exist. Two clients racing to create the same resource then produce one success and one `412`, instead of a silent duplicate or one overwriting the other.

```http
PUT /articles/42
If-None-Match: *
→ 2xx                       # write proceeds; the controller's own status

PUT /articles/42
If-None-Match: *
→ 412 Precondition Failed   # someone else created it first
```

`If-None-Match` on a write is not only the wildcard. A concrete entity tag works too, compared **weakly** per RFC 9110 §13.1.2: if it matches the resource's current validator the write is refused with `412`, and otherwise it proceeds — the same rule the read path already applies, just guarding a write instead of serving a `304`.

> [!IMPORTANT]
> A concrete `If-None-Match` does **not** satisfy `required`. Only two field values state a version the client believes it is writing over: an `If-Match`, whatever it names, and `If-None-Match: *`, which says "only if nothing is there". A concrete `If-None-Match` says neither, so on a `conditional:required` route it is answered `428` whatever it names — including a tag that matches, which would be `412` on a route without the flag. Anything less would defeat the flag outright: the very tag `If-Match` refuses with `412` proceeded once the client moved it to the other header, and so did `"0"`, `garbage`, and `W/`. Use `If-Match` to guard an update and `If-None-Match: *` to guard a create; the weak comparison above still applies on routes without `required`.

For the guard to be able to answer, the route has to address the resource being created and its binding has to be able to report "absent" rather than aborting. Implicit binding raises a `404` for a missing record before the middleware ever runs, so register an explicit binder that returns `null`:

```php
Route::bind('article', fn (string $value): ?Article => Article::query()->find($value));
```

That binder alone is not enough. `SubstituteBindings::handle()` runs `substituteBindings()` — which sets the route parameter to whatever the binder above returned, `null` included — and then `substituteImplicitBindings()`, which re-resolves any action parameter type-hinted against the model. A `null` parameter fails that re-resolution and throws `ModelNotFoundException`, a `404` before `conditional:required` ever runs. So the action must **not** type-hint the model; take `Request` (or the raw route parameter) and resolve the record yourself:

```php
use Illuminate\Http\Request;

Route::put('/articles/{article}', function (Request $request) {
    $article = $request->route('article'); // Article|null — not type-hinted, see above

    if ($article === null) {
        // create the record, then return the response your app wants for a new resource
    }

    // update $article, then return the response your app wants for that write
})->middleware('conditional:required');
```

On a collection route such as `POST /articles` there is no bound resource to ask about, so the create guard has nothing to compare and the request proceeds.

## If-Unmodified-Since

The date-based fallback for a client that has a `Last-Modified` and no tag. It is evaluated only when `If-Match` is absent, per RFC 9110 §13.2.2.

```http
PUT /articles/42
If-Unmodified-Since: Wed, 26 Aug 2026 12:00:00 GMT
→ 200 OK                    # unchanged since that date, write applied

PUT /articles/42
If-Unmodified-Since: Wed, 26 Aug 2026 11:59:59 GMT
→ 412 Precondition Failed   # it has changed since
```

It satisfies `required`, so a client that sends it is not answered `428`.

> [!WARNING]
> **Prefer `If-Match`.** A date is a one-second validator, so two writes inside one second are indistinguishable to it. This package closes that window for its own clients — a date is never published while the second holding the change is still open, so a client echoing back a date we gave it cannot be misled — but a client that invents a date from its own clock has no such guarantee. `If-Match` compares entity tags and has no such window. See [H17](hazards.md#h17).

> [!IMPORTANT]
> A resource that publishes no date refuses an `If-Unmodified-Since` with `412` rather than ignoring it. That covers a model with no timestamps, a record that changed within the current second, and `last_modified => false`. A client that sends this header is asking to be refused when the server cannot vouch for the state; proceeding would hand it a guard that silently does nothing.

> [!IMPORTANT]
> **Only the three formats RFC 9110 §5.6.7 defines are HTTP-dates**: IMF-fixdate (`Wed, 26 Aug 2026 12:00:00 GMT`), the obsolete RFC 850 form, and asctime (`Wed Aug 26 12:00:00 2026`, with no zone). Anything else — `x`, `now`, `tomorrow`, `+1 day`, an empty template placeholder — is ignored per §13.1.4, exactly as if the header had not been sent, so it neither guards a write nor satisfies `required` on a route that demands one. PHP's `strtotime()` parses all of those to real timestamps; this package does not use it here, because a value that resolves to "now" would satisfy every precondition it was asked about and silently turn the guard into a no-op.

`If-Modified-Since` is a read-path header and is ignored on a write (§13.1.3), so it neither guards a write nor satisfies `required`. The date these guards compare against is the one [`reads.md`](reads.md#last-modified-and-if-modified-since) publishes.

## Closing the race — `lock`

`If-Match` on its own is check-then-write. The middleware reads the current version, decides the write is safe, and calls your controller — and in between those two things another request can commit. The window is small and it is real: under concurrency a `conditional:required` route loses fewer updates than an unguarded one, not none.

`lock` closes it.

```php
Route::patch('/articles/{article}', UpdateArticle::class)
    ->middleware('conditional:required,lock');
```

The middleware then opens a transaction on the record's own connection, re-reads the record with `SELECT … FOR UPDATE`, **evaluates `If-Match` a second time against what came back**, and only then runs your controller — inside the same transaction, still holding the lock. From the lock until the commit nothing else can change that row, so the version your controller writes on top of is the version it was promised.

The second evaluation is the point. A lock without it would be the same race with more machinery.

Your controller is handed the freshly locked instance, not the one route-model binding resolved, so `$article->version` is what the lock read.

### What `lock` costs

`lock` is off unless you ask for it, per route, and this is why. The full account is [H9](hazards.md#h9); the bullets are repeated here because you are about to turn the flag on and should not be able to do that without reading them.

- **Your controller runs inside a transaction.** Everything it does is in the same unit of work. If it opens its own transaction, Laravel savepoints it and that works fine.
- **A job dispatched inside your controller runs before the commit.** This is Laravel's ordinary behaviour inside any transaction and this package cannot change it. Use `dispatch($job)->afterCommit()`, or set `after_commit => true` on the queue connection.
- **The row stays locked for as long as your controller takes.** Keep guarded routes lean. A slow guarded route is a queue of waiting writers.
- **A response is not a rollback.** Returning a `500` from your controller commits the transaction, exactly as it would inside a hand-written `DB::transaction()`. Throw if you want the work discarded.
- **Only the target row is locked, on only that record's connection.** Related rows your controller writes, and writes it makes through a different connection, are outside both.
- **An outer transaction takes ownership of the lock.** If a transaction is already open on that connection when the middleware runs — an application-level wrapper around the request, or a test's own `DatabaseTransactions` — the transaction opened here is a savepoint inside it. The row stays locked until *that* transaction commits, not until this one does, and `lock_timeout` is not applied at all: bounding the wait would mean retuning a transaction this package did not open, and on PostgreSQL `SET LOCAL` would outlive the savepoint and go on bounding every later statement in it. The re-read, the `FOR UPDATE`, and the second evaluation happen exactly as they otherwise would.
- **A `503` means the row was busy.** If the lock cannot be taken within `lock_timeout` seconds the request is answered `503 Service Unavailable` with a `Retry-After`, and nothing is written. Catch `ExpertSystems\ConditionalRequests\Exceptions\LockTimeoutException` in your handler to answer differently. On SQL Server there is no such bound unless you set one on the connection yourself — see [`lock_timeout`](#lock_timeout) — so a guarded write there waits for as long as the competing transaction holds the row.

> [!WARNING]
> `lock` needs a database that implements row locking. **On SQLite it does not lock at all** — see [H10](hazards.md#h10).

### `lock_timeout`

```php
'lock_timeout' => 5,
```

Seconds to wait for the row before giving up with `503`. Applied per request on PostgreSQL (`SET LOCAL lock_timeout`, transaction-scoped) and on MySQL / MariaDB (`SET SESSION innodb_lock_wait_timeout`, restored afterwards). Other drivers ignore it.

SQL Server is the one driver with an equivalent this package does not use: it has `SET LOCK_TIMEOUT`, but the package never issues it, so SQL Server's own default of `-1` — wait forever — applies whatever you put in this key. Set it on the connection yourself if you want a bound there. A guarded write on `sqlsrv` otherwise holds its PHP worker for as long as the competing transaction holds the row, and no `503` is ever produced by the wait. A lock error SQL Server *does* raise, `1222`, is still recognised and still answered `503`.

Set `0` to leave your server's own setting alone — but note that PostgreSQL's `lock_timeout` defaults to `0`, which means wait forever.

### Creates under `lock`

`lock` has nothing to hold on a create: there is no row yet. A `POST` to a collection under `conditional:required,lock` behaves exactly as it does under `conditional:required` — `If-None-Match: *` guards it and no transaction is opened. Back that up with a unique constraint; a package cannot.

### Wanting a different lock

`ModelStrategy::lockingQuery()` is `@internal` and `ModelStrategy` is `final readonly`, so there is nothing to override. The supported route is decoration: implement `LockableValidatorStrategy` yourself, delegate everything you do not want to change, and write your own locking read.

```php
use ExpertSystems\ConditionalRequests\Contracts\LockableValidatorStrategy;
use ExpertSystems\ConditionalRequests\Validators\ModelStrategy;
use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SharedLockStrategy implements LockableValidatorStrategy
{
    public function __construct(private ModelStrategy $inner = new ModelStrategy) {}

    public function fromRequest(Request $request): ?Validator
    {
        return $this->inner->fromRequest($request);
    }

    public function fromResponse(Request $request, Response $response): ?Validator
    {
        return $this->inner->fromResponse($request, $response);
    }

    public function targetExists(Request $request): ?bool
    {
        return $this->inner->targetExists($request);
    }

    public function lockTarget(Request $request): ?Model
    {
        return $this->inner->lockTarget($request);
    }

    public function lockAndRefresh(Request $request, Model $target): ?Model
    {
        // Your own locking read here. Whatever it returns must also be what
        // fromRequest() answers from afterwards — rebind the route parameter,
        // exactly as ModelStrategy::lockAndRefresh() does — or the second
        // evaluation reads the same stale record as the first.
        return $this->inner->lockAndRefresh($request, $target);
    }
}
```

Register it and name it as a flag exactly like a built-in one:

```php
public function boot(ConditionalRequests $conditional): void
{
    $conditional->extend('shared', fn (): ValidatorStrategy => new SharedLockStrategy);
}
```

```php
Route::patch('/articles/{article}', UpdateArticle::class)
    ->middleware('conditional:shared,required,lock');
```

This is an extension point, not a recommendation. **A shared lock is a weaker guarantee wearing the same flag**: it does not stop another writer taking an exclusive lock the moment you release, so the window `lock` exists to close is only narrowed rather than shut.

## Requirements and caveats for guarded routes

> [!WARNING]
> A **write** route that binds more than one record implementing `ProvidesConditionalValidator` is a configuration error and throws a `LogicException`, naming the route and the candidate parameters. On a read the [first-wins rule](hazards.md#h4) is merely imprecise; on a write it inverts the guard. `PATCH /articles/{article}/comments/{comment}` guards the *article* while the controller writes the *comment* — so the client that correctly sends the comment's tag is refused with `412`, and the write lands only when it sends the tag of a record it is not touching. Implement the contract on the record the route represents and not on the others, or take the conditional middleware off the route. Folding the other record in by overriding `conditionalValidator()` is the *read*-path remedy and does not clear this error — the count is of records implementing the contract, not of validators produced. The read path is unaffected and keeps first-wins.

> [!IMPORTANT]
> A precondition is never silently discarded. The guard needs a strategy that can produce the current validator *before* the controller runs, and the default strategy — `body` — cannot: it describes a response that does not exist yet. A write to such a route carrying an `If-Match` or an `If-None-Match` is therefore refused with `412`, because the client asked for a guarantee the route cannot provide and answering `200` would tell it the check had passed. A write carrying no precondition still passes straight through, so the guard stays opt-in and `Route::resource(...)->middleware('conditional')` keeps working for every client that sends nothing. To actually guard those writes, name a strategy that can answer — `conditional:model`, or `conditional:required`. The refusal applies to **route** and **group** placement, where the route is resolved and the strategy named on it is the one that will be asked. A **kernel-global** instance runs ahead of the router, cannot see the route's flags, and defers instead: it passes the write on so the route's own `conditional` — `required` or `model` — decides. Registering `conditional` globally for read-path `ETag`s therefore does not break the guarded write routes underneath it, but it also adds no write guard of its own. **That deferral depends on the global instance's strategy being one that cannot answer before the controller, which `body` — the default — is.** Set `strategy => 'model'` and a global instance guards every write itself, against a route it has not seen: `412` for every client sending a correct `If-Match`, `200` for every client sending nothing. See [H12](hazards.md#h12).

> [!IMPORTANT]
> `conditional:required` must run **after** `SubstituteBindings`, and its model must produce a validator. Inside the `api` or `web` middleware group the ordering is already right. Get it wrong — kernel-global placement, or a hand-written list that puts `conditional` first — and the guard cannot see the record at all: every `If-Match` is refused with `412`, and so is every `If-None-Match: *`, because a strategy that cannot tell whether the target exists fails the create guard closed. **On the read path a wrong ordering only costs the compute saving; on the write path it stops writes.** See [`placement.md`](placement.md#what-must-run-before-conditional).

> [!IMPORTANT]
> `enabled => false` is not only a caching kill switch. The write path checks it first, so turning it off removes every lost-update guard in the application at the same time: a `conditional:required` route stops answering `428`, stops refusing a stale `If-Match` with `412`, and applies the write. Flipping it while debugging a caching problem quietly reopens the mid-air collision it was never about. `exclude` does the same thing for the routes it matches, and is the narrower tool.

> [!IMPORTANT]
> Under kernel-global placement only half of `exclude` can suppress the write guard. The decision has to precede the controller, and nothing has been routed at that point, so `Request::routeIs()` answers false for every pattern: a **route-name** exclusion such as `admin.*` is silently ignored on the write path there. **URI** patterns such as `internal/*` still work, as does `enabled => false`. Under route or group placement — the ordering the caveat above already requires — both halves work as documented. See [H12](hazards.md#h12).

> [!IMPORTANT]
> A model with no `version` column and no timestamps produces no validator, so it can satisfy no precondition: `If-Match: *` returns `412` every time, and so does `If-None-Match: *` — the record exists, and the create guard refuses to write over it. Add a `version` column or enable timestamps — the same rule the read path already needs.

> [!NOTE]
> The create guard writes only when the target is **definitely** absent. "Does this resource exist?" is asked of the strategy separately from "what is its version?", through `RequestValidatorStrategy::targetExists()`, and `If-None-Match: *` proceeds only on a definite *no*. A `null` — the strategy cannot tell — is `412`. Under `model` that covers a route whose bindings have not been substituted and a guard running before the router; a bound record answers *yes* whether or not it produces a validator, and a binder that returned `null`, or a collection route addressing no record at all, answers *no*. A custom `RequestValidatorStrategy` has to answer the same question, and answering `false` when it does not know reopens the hole: a live record silently overwritten by the one precondition meant to protect it.

> [!WARNING]
> Setting `weak => true` on a conditional write route is a configuration error and throws a `LogicException`, naming the config key. A weak validator can never satisfy `If-Match`, so the guard is not merely disabled but **inverted**: every client sending the correct token is refused with `412`, and every client sending nothing writes freely, with nothing in either response to say why. The error is raised on the first request that would evaluate an `If-Match` against a weak validator, and on the first guarded request of any kind when the route is flagged `required` — where the pairing is fatal before a client sends anything at all. A write carrying no precondition on a route without `required` is guarded by nothing and still passes. The same `LogicException` covers naming a strategy that cannot produce a validator before the controller runs, such as `conditional:body,required`.

> [!NOTE]
> The preconditions are evaluated in RFC 9110 §13.2.2 order: `If-Match` first, then `If-Unmodified-Since` in its absence, then `If-None-Match`. A request carrying only `If-Unmodified-Since` therefore satisfies `required` — see [If-Unmodified-Since](#if-unmodified-since) for what it can and cannot promise.

> [!NOTE]
> `If-Match` closes the window between the client's read and its write, not the window between this check and the controller's own write. Two writes that both pass the guard microseconds apart can still race. Add the [`lock` flag](#closing-the-race--lock) to close that window too — it re-evaluates the precondition inside a transaction holding a row lock.

Both refusals are `Symfony\Component\HttpKernel\Exception\HttpException` subclasses, so your application's existing exception handler renders and customises them:

```php
use ExpertSystems\ConditionalRequests\Exceptions\PreconditionFailedException;

$exceptions->render(function (PreconditionFailedException $e) {
    return response()->json(['error' => 'stale'], 412);
});
```

Their default bodies live in `lang/en/messages.php`; publish it with the `laravel-conditional-requests-lang` tag to reword them.
