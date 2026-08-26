# Hazards

## How to use this page

Every entry has the same four parts:

- **What happens** — the observable consequence, not the mechanism.
- **You are exposed if** — the conditions that have to hold. If none of them do, skip the entry.
- **What to do** — the remedy, or the explicit statement that there is none.
- **Why it works this way** — the mechanism, and why it was not simply fixed.

Entry numbers are stable. `H7` stays `H7` even if the ordering changes, so a link to `hazards.md#h7` from an issue or a code comment keeps working.

Nothing here is a bug report. Every one of these is either inherent to answering a request before the controller runs, inherent to HTTP validators, or a consequence of a decision taken deliberately and recorded in [the design](design/2026-08-25-conditional-requests-design.md). Where something *is* a defect that has not been fixed, the entry says so.

## Scan table

| # | You are exposed if | What it costs you |
| --- | --- | --- |
| [H1](#h1) | you use `conditional:model` (or `required` / `lock`, which imply it) and anything that can reject a request is declared *after* `conditional` | a revoked client keeps reading |
| [H2](#h2) | a terminable middleware is declared after `conditional` on a route that can short-circuit | teardown against uninitialised state |
| [H3](#h3) | your model has a column literally named `version` that does not change on every write | permanent `304` against changed content |
| [H4](#h4) | a route binds more than one model implementing the contract | the tag tracks the wrong record |
| [H5](#h5) | one URL serves different bytes to different viewers, tenants, or `Accept` headers, under `model` | one client's tag honoured for another |
| [H6](#h6) | you set `Cache-Control` from middleware declared *outside* `conditional`, under `model` | a `public` cache entry permanently downgraded |
| [H7](#h7) | any middleware, global or route-level, changes the response body *outside* `conditional` | the tag describes bytes nobody received |
| [H8](#h8) | a reverse proxy compresses responses | `304` never happens in production |
| [H9](#h9) | you use `conditional:...,lock` | your controller runs inside a transaction |
| [H10](#h10) | you use `lock` on SQLite | no lock at all |
| [H11](#h11) | anything sets an `ETag` before `conditional` sees the response | validators silently stop working |
| [H12](#h12) | `conditional` is registered as kernel-global middleware | no short-circuit, untagged `HEAD`s, and half of `exclude` |
| [H13](#h13) | you publish the config file | a typo is a production outage, not a degradation |
| [H14](#h14) | response bodies contain attacker-influenced content and serving a stale copy matters | a crafted body suppresses a client refresh |
| [H15](#h15) | you call `extend()` anywhere but a service provider's `boot()` | permanently mutated shared state |
| [H16](#h16) | your model casts its primary key to an enum or an object | no `ETag` at all, silently |
| [H17](#h17) | a record can change twice inside one second | colliding tags, and a withheld `Last-Modified` |
| [H18](#h18) | your controller inspects `$request->method()` under the `body` strategy | it sees `GET` for a `HEAD` request |

## The register

<a id="h1"></a>

### H1 — A pre-controller `304` answers before anything declared after `conditional`

**What happens.** A client that holds a valid entity tag keeps receiving `304 Not Modified` for a record it is no longer allowed to read.

**You are exposed if** you use `conditional:model` — or `conditional:required` / `conditional:lock`, both of which imply `model` — or any custom strategy that answers from the request, **and** something that can reject the request is declared after `conditional`: `can:`, `signed`, a subscription or feature gate, a tenant check, a custom rate limiter. A per-record check written inside the controller action is in that position too.

**What to do.** Place `conditional` **after** every middleware that can reject the request, so a request that should be rejected never reaches the strategy. Move per-record checks out of the controller action and into middleware, because the action does not run on a hit. There is no arrangement that keeps both the compute saving and a check that runs after `conditional` — the saving *is* the skipping.

**Why it works this way.** `Conditional::handle()` returns the response built by `notModified()` before it calls `$next($request)`. Nothing after this middleware in the pipeline is entered at all. That is the entire value of the `model` strategy and it cannot be had any other way.

Two entry points into this hazard are closed rather than left to you, and the scope correction matters:

- A client that holds **no** tag cannot trigger it. `If-None-Match: *` matches every validator there is, so a bare wildcard once made the status code an existence oracle for any id, with the gate never entered. `Conditional::wildcardOnly()` now refuses to short-circuit a wildcard unless a concretely matching tag arrived with it, so a wildcard read takes the ordinary path and every gate runs.
- A lone `If-Modified-Since` is the same shape — a date needs no prior access — and `Conditional::dateOnly()` closes it the same way.

What remains is a client that legitimately obtained a tag and later lost access. That is inherent.

`ThrottleRequests` specifically **cannot** be bypassed this way, and the reason is worth knowing. `SortedMiddleware` reorders priority middleware among themselves and leaves a non-priority entry such as `conditional` where the route put it — and on Laravel's `$middlewarePriority` list `ThrottleRequests` is sorted ahead of `SubstituteBindings`. The short-circuit needs the bindings, so any arrangement in which it can fire is one where throttling has already run. A *custom* limiter that is not on the priority list has no such protection.

Verified by `tests/Feature/AuthorizationOrderingTest.php`, which proves both the `403`-before-`conditional` ordering and that a wildcard, a weak-form wildcard, and a date each reach authorization.

<a id="h2"></a>

### H2 — `terminate()` runs even though `handle()` did not

**What happens.** A terminable middleware's teardown runs against state its own setup never created. A request timer subtracts from `null` and reports the epoch; a metric emitter closes a span it never opened; a log context is popped that was never pushed.

**You are exposed if** a terminable middleware is declared after `conditional` on a route whose reads can short-circuit.

**What to do.** Declare terminable middleware **outside** `conditional`, where both halves run as usual — or make `terminate()` tolerate its own state being absent.

**Why it works this way.** `Illuminate\Foundation\Http\Kernel::terminateMiddleware()` calls `terminate()` on everything `gatherRouteMiddleware()` returns, whether or not that middleware executed. As far as the kernel is concerned the middleware was on the route. Nothing in this package can change that.

Under Octane it is worse than `null`: the worker still holds whatever the *previous* request left in that property, so the teardown runs against another request's state rather than against nothing.

<a id="h3"></a>

### H3 — A `version` column must change on every single write

**What happens.** The entity tag freezes. The route keeps answering `304` against content that has changed, indefinitely.

**You are exposed if** the model already has a column literally named `version` that means something other than "this row changed".

**What to do.** Override `conditionalVersionColumns()` to point at something that does mean *this row changed*. Returning an empty list from it stops the model producing a validator at all, which is a safe answer.

**Why it works this way.** `HasConditionalValidator::conditionalVersionColumns()` returns `['version', <the updated_at column>]` and `conditionalVersion()` takes the first non-empty value in that order — so a column named `version` wins over `updated_at` unconditionally, with no way to notice.

Three shapes, all of them real:

- **A pre-existing `version` that means something else** — a document revision label, a schema version, a counter only some code paths bump. The tag stops moving when that column stops moving.
- **A nullable one splits the table.** Rows where it is `null` fall through to `updated_at`; rows where it holds a value are tracked by the column. Backfilling it once silently stops those rows tracking edits.
- **Soft deletes.** `runSoftDelete()` bumps `deleted_at` and `updated_at` and never `version`, so a soft delete does not move the tag on a model that has one.

<a id="h4"></a>

### H4 — On a nested route the first bound model wins

**What happens.** On a read, the tag tracks a record the route is not really about. On `/articles/{article}/comments/{comment}` the tag tracks the *article*, so editing the comment never moves it and clients keep getting `304`. On `/{tenant}/articles/{article}` every article under a tenant shares the tenant's single tag.

**You are exposed if** a route binds more than one parameter whose model implements `ProvidesConditionalValidator`.

**What to do.** Implement the contract only on the record the route actually represents, or override `conditionalValidator()` on the outer record to fold the inner one's version into the tag.

**Why it works this way.** `ModelStrategy::target()` iterates `$route->parameters()`, which keep their declaration order, and returns the first parameter implementing the contract. First rather than best is deliberate: deterministic beats clever, and the target never moves with runtime state.

The **write** path does not take that risk. `ModelStrategy::targetExists()` counts the parameters implementing the contract and throws a `LogicException` naming the route and the candidates when there is more than one, because on a write the ambiguity inverts the guard — the client naming the record it is modifying is refused with `412` while the one naming a record it is not touching writes. Folding the other record in via `conditionalValidator()` is the *read*-path remedy and does not clear that error: the count is of records implementing the contract, not of validators produced.

<a id="h5"></a>

### H5 — A model-derived tag is scoped to the record and to nothing else

**What happens.** Two callers who should see different bytes are given the same entity tag, so a tag minted for one is honoured for the other and the second caller is told `304` about a representation it has never held.

**You are exposed if** you use `model` (or `required` / `lock`) on a URL that can serve different bytes to different viewers, different tenants, or different `Accept` headers.

**What to do.** Override `conditionalValidator()` and fold the request-dependent input into the tag — see [What a model-derived tag is scoped to](reads.md#what-a-model-derived-tag-is-scoped-to) for the worked example. Pass `lastModified` through when you rebuild the validator; a rebuild that drops it silently removes the `Last-Modified` header for that model.

**Why it works this way.** The default fingerprint is `[connection database name, connection table prefix, table, key, version]`, assembled in `HasConditionalValidator::conditionalLocation()` and `conditionalVersion()`. Nothing about the viewer, the tenant row, or the representation is in it. Under `body` this came free, because the tag was a hash of the bytes actually sent. Under `model` it does not.

Three cases:

- **The viewer.** A route where an admin sees extra fields collapses every viewer to one tag. A tag minted for the admin's response is then honoured for anyone who presents it.
- **The tenant.** Database-per-tenant and prefix-per-tenant **are** separated, because the connection's database name and table prefix are both in the fingerprint. **Single-database row-level tenancy is not**: one database, one prefix, one table, so tenant A's row 1 at version 1 and tenant B's produce the same tag. Both halves matter — the covered half is what makes the uncovered one surprising.
- **The representation.** Content negotiation, sparse fieldsets, `?include=`. Verified concretely against a real application: one URL served `<h1>Changed</h1>` to `Accept: text/html` and `{"id":1,…}` to `Accept: application/json` under an **identical strong ETag with no `Vary` header**.

<a id="h6"></a>

### H6 — A short-circuited `304` substitutes a `Cache-Control` your application did not choose

**What happens.** A cached entry's policy is destroyed the first time it successfully revalidates. A `public, max-age=60` entry becomes `private` — out of every shared cache — and `no-cache`, so it revalidates on every request from then on.

**You are exposed if** you set `Cache-Control` from middleware declared *outside* `conditional`, on a route using `model`.

**What to do.** Set cache headers from middleware declared **inside** `conditional`. A header already on the response before Symfony marks it not-modified survives, because `setNotModified()` does not strip `Cache-Control`.

**Why it works this way.** `Conditional::notModified()` builds a fresh `Illuminate\Http\Response`, which carries Symfony's default `Cache-Control: no-cache, private`. Middleware that skips empty responses never applies the application's policy to it — Laravel's own `SetCacheHeaders` returns early for a contentless non-`HEAD`, non-binary, non-streamed response. RFC 9111 §4.3.4 then requires a cache to update its stored headers from the `304`, so the downgrade is permanent.

The nuance, or the entry misleads: middleware that sets headers **unconditionally** on the way out survives regardless of position. It is specifically middleware that skips empty responses that loses them.

<a id="h7"></a>

### H7 — Response-mutating middleware outside `conditional` breaks the tag↔bytes contract

**What happens.** One strong entity tag is served with several different bodies. A client revalidating with it is told `304` every time, so it pins the first CSP nonce for ever while the server goes on minting new ones — or keeps a stale CSRF token in every form on a cached page.

**You are exposed if** anything rewrites the response body outside `conditional`: an HTML minifier, a CSP-nonce injector, a CSRF token refresher, a debug bar, a response filter of any kind. **Global middleware always run outside route middleware**, so a global rewriter is always in that position; a route middleware declared before `conditional` is too.

**What to do.** Declare body-mutating middleware **inside** `conditional` — `->middleware(['conditional', 'inject-nonce'])`, in that order — so the bytes are final before they are hashed. Or take those routes out with the `exclude` config key. Under `model` the ordering does not help at all, because that tag never described the bytes: a per-response nonce is exactly what [H5](#h5) says has to be folded in by hand, or kept off a conditional route.

**Why it works this way.** `Conditional::attach()` hashes `$response->getContent()` at `conditional`'s own position in the stack, and anything further out mutates the body after the hash was taken.

Verified against a real application: `['wrap:…', 'conditional']` served the pre-mutation hash and `['conditional', 'wrap:…']` was correct.

<a id="h8"></a>

### H8 — A reverse proxy rewrites or drops the tag when it compresses

**What happens.** `304` works in local development and never in production.

**You are exposed if** a reverse proxy compresses your responses.

**What to do.** Diagnose it in one command: `curl -sI -H 'Accept-Encoding: gzip' <url>` against the proxy and against the application directly. The difference is immediate. Then configure the proxy to preserve the tag, or move compression behind the validator.

**Why it works this way.** A validator identifies one specific set of bytes, and compression changes those bytes. nginx drops `ETag` outright when it gzips; Apache appends `-gzip`. Either way the tag the client holds is not the tag this middleware computed, so `If-None-Match` never matches.

Not verified in this repository — there is no proxy in the integration harness. The behaviour is the proxies', not this package's.

<a id="h9"></a>

### H9 — `lock` runs your controller inside a transaction

**What happens.** Everything your controller does on a guarded write becomes part of one unit of work you did not open, with the consequences below.

**You are exposed if** a route carries the `lock` flag.

**What to do.** Keep guarded routes lean. Use `dispatch($job)->afterCommit()`. Throw rather than return when you want work discarded. Catch `LockTimeoutException` if a busy row should be answered differently.

**Why it works this way.** `Conditional::locked()` wraps `$next()` in `Connection::transaction()` on the target record's own connection, with the default of one attempt, so that the precondition can be re-evaluated against a row nothing else can change until the commit.

Six consequences:

- Everything the controller does is in one unit of work.
- **A job dispatched inside it runs before the commit** unless `afterCommit()` is set, or `after_commit => true` on the queue connection. This is Laravel's ordinary behaviour inside any transaction.
- The row stays locked for the controller's whole duration, so a slow guarded route is a queue of waiting writers.
- **An error response is not a rollback.** Returning a `500` commits, exactly as it would inside a hand-written `DB::transaction()`, because `Connection::transaction()` inspects control flow and not status codes.
- Only the target row on only that record's connection is locked. Related rows, and writes made through a different connection, are outside both.
- A wait longer than `lock_timeout` is answered `503` with a `Retry-After`, and nothing is written.

An outer transaction takes ownership. If one is already open on that connection — an application wrapper, or a test's `DatabaseTransactions` — this one becomes a savepoint inside it, the row stays locked until *that* transaction commits, and `lock_timeout` is not applied at all. The re-read, the `FOR UPDATE`, and the second evaluation are unaffected.

<a id="h10"></a>

### H10 — `lock` takes no lock on SQLite

**What happens.** The flag is accepted, the code path runs, and no exclusion is obtained. The window between the re-read and the write stays open.

**You are exposed if** a `lock` route's model lives on a SQLite connection.

**What to do.** Use MySQL, MariaDB, PostgreSQL, or SQL Server for routes that need the guarantee. On SQL Server the lock and the re-evaluation are correct but `lock_timeout` is not issued, so the wait is unbounded unless you bound it on the connection yourself.

**Why it works this way.** `SQLiteGrammar::compileLock()` returns the empty string, so `lockForUpdate()` compiles to nothing. The re-read and the second evaluation still happen and still catch a competitor who has already committed, which is a real improvement over no `lock` at all — but there is no mutual exclusion.

<a id="h11"></a>

### H11 — An `ETag` already on the response turns everything off

**What happens.** The route advertises a tag, and `304` handling silently stops working. Under `model` the controller runs on every request, including the ones that end in a `304`.

**You are exposed if** anything sets an `ETag` before `conditional` sees the response — most commonly `cache.headers:...;etag`, or a second `conditional` stacked outside the first.

**What to do.** Do not combine `conditional` with anything else that sets an `ETag` on the same route. Pick one tag source. If the other one has to stay, put it **outside** `conditional` so `conditional` tags first.

**Why it works this way.** `Conditional::eligible()` returns `false` when `$response->getEtag() !== null`. That rule exists so the package never overwrites a tag the application chose — but "left alone" and "takes no part in `304` handling" are the same sentence, and only the first half is obvious.

The sharp case is `['conditional:model', 'cache.headers:public;max_age=60;etag']`. The inner middleware sets a body-hash tag first, `conditional` then declines, and the route advertises a tag the model short-circuit can never match. You asked for the compute-saving strategy and silently got the bandwidth-only one. Verified against a real application with a controller hit counter, which proved the controller ran on every `304`.

<a id="h12"></a>

### H12 — Kernel-global placement gives up the short-circuit, `HEAD` tags, and half of `exclude`

**What happens.** Four things, none of them errors and all of them silent:

- **No pre-controller short-circuit, ever.** `conditional` runs before `SubstituteBindings`, so `model` finds no bound record and the controller runs on every request. The tag is still attached on the way out, so `304` still happens — the bandwidth saving survives and the compute saving does not.
- **`HEAD` requests under `body` go untagged.** The `HEAD`-to-`GET` mutation is deliberately not applied before routing, so the router empties the body for the `HEAD` it can see and there is nothing left to hash.
- **Route-name exclusions are ignored on the write path.** `Request::routeIs()` answers false for every pattern before routing, and the write path has no second chance. URI patterns such as `internal/*` still work, as does `enabled => false`.
- **A global instance adds no write guard.** Ahead of the router it cannot read a route's flags, so it defers rather than refusing a precondition it cannot evaluate — which is what keeps the `conditional:required` and `conditional:model` guards on the routes underneath it working.

**You are exposed if** `conditional` is pushed onto the kernel's global middleware stack rather than named on a route or a group.

**What to do.** Prefer route or group placement. Global placement remains supported, and `Conditional::excluded()` is deliberately re-checked after `$next()` so that route-name exclusions are honoured on the read path there.

**Why it works this way.** Global middleware runs outside the router. Two consequences that used to be worse are closed in code and are **not** live: a `304` manufactured at that position once leaked PHP's `default_mimetype` as a `Content-Type` (`Conditional::complete()` now calls `Response::prepare()` on both paths — see `tests/Feature/NotModifiedPreparationTest.php`), and a `HEAD` was once routed as a `GET`, so a `Route::match(['HEAD'], …)` route answered `405` (the mutation is now gated on a resolved route — see `tests/Feature/HeadRequestTest.php`). What is left is the list above, which is placement cost rather than defect.

<a id="h13"></a>

### H13 — A bad `strategy` or `hash` value is a per-request `500`, not a degradation

**What happens.** Every conditional route in the application returns `500`. Caching does not quietly stop; the site goes down.

**You are exposed if** you publish the config file and can therefore mistype `strategy` or `hash`. Flag matching is **case-sensitive**, so `conditional:MODEL` is an unregistered strategy name and does the same thing.

**What to do.** Cover at least one conditional route in a smoke test that runs on deploy, and treat this config file as deploy-critical. There is no boot-time validation and none is planned — a boot-time route scan cannot see controller-declared middleware, so it cannot enumerate every route to check.

**Why it works this way.** `ConditionalRequests::strategy()` throws `InvalidArgumentException` naming the unregistered name and listing the registered ones. `BodyHashStrategy::__construct()` throws when `hash_algos()` does not contain the algorithm, and the strategy is constructed **before** `$next()` so the short-circuit can consult it — which means the failure now reaches requests that would have ended in a `404`, a stream, or an oversized body.

<a id="h14"></a>

### H14 — `xxh128` is not collision-resistant against a chosen input

**What happens.** An attacker who can influence a response body can craft one that hashes to the same tag as an earlier version, so a client holding the earlier one is told `304` and never refreshes.

**You are exposed if** response bodies carry attacker-influenced content **and** serving a stale representation matters. Both halves have to hold.

**What to do.** Set `hash` to a cryptographic algorithm. The cost is throughput on large payloads and one round of cache invalidation.

**Why it works this way.** The default is a change-detection fingerprint, not a security primitive (design §6). Collision from *incidental* change is irrelevant at this scale and is not the concern. Model-derived tags do not read this key: they hash a database name, a table prefix, a table, a key and a version column, none of which a client chooses.

<a id="h15"></a>

### H15 — `extend()` outside a provider's `boot()` mutates shared state permanently

**What happens.** A strategy registration made from request-handling code stays registered for the rest of the process — under Octane, for the whole worker and every subsequent request it serves.

**You are exposed if** you call `extend()` from a controller, a route closure, a job, or any other request handler.

**What to do.** Call it from a service provider's `boot()` only. Laravel resolves `boot()` parameters from the container, so type-hint the registry and you get a typed call:

```php
public function boot(ConditionalRequests $conditional): void
{
    $conditional->extend('revision', fn (): ValidatorStrategy => new RevisionStrategy);
}
```

**Why it works this way.** The registry is a container singleton, bound in `ConditionalRequestsServiceProvider::register()`. There is one instance and `extend()` writes to it.

<a id="h16"></a>

### H16 — A cast primary key silently produces no validator at all

**What happens.** The route gets no `ETag`, no `Last-Modified`, and no error. Nothing anywhere says why.

**You are exposed if** the model casts its primary key to an enum or a value object.

**What to do.** Override `conditionalValidator()` and supply the raw key yourself.

**Why it works this way.** `HasConditionalValidator::conditionalValidator()` returns `null` unless `getKey()` yields a `string` or an `int`, and `getKey()` returns the **cast** value. It fails safe, which is right — inventing a tag would be worse — but it fails silently, which is the problem.

<a id="h17"></a>

### H17 — One-second granularity, on both validators

**What happens.** Two writes inside the same second are indistinguishable. Both produce the same entity tag, and the `Last-Modified` header is withheld for the remainder of the second in which a record changed.

**You are exposed if** a record can change twice inside one second.

**What to do.** Add a `version` column, or widen the timestamp column's precision, for resources that change that fast. Widening helps the `ETag` and **cannot** help `Last-Modified`.

**Why it works this way.** Two separate facts:

- `updated_at` is stored to the second by default, so two writes inside one second produce the same raw value and therefore the same tag. Verified: two saves in one second on a `datetime` column produced byte-identical tags with different titles.
- `Last-Modified` is an HTTP-date with whole-second resolution (RFC 9110 §5.6.7), which no column can fix. RFC 9110 §8.8.2.2 permits a date validator to be treated as strong only when the server knows the representation did not change twice inside the second it names — unknowable while that second is still running. The package therefore **withholds the header until the second holding the change has elapsed**; see [`reads.md`](reads.md#last-modified-and-if-modified-since).

<a id="h18"></a>

### H18 — Under `body`, your controller sees `GET` for a `HEAD` request

**What happens.** Controller code calling `$request->method()` or `$request->isMethod('HEAD')` sees `GET` for the duration of that call.

**You are exposed if** a route uses the `body` strategy — or any strategy that did not answer from the request — **and** the controller branches on the request method.

**What to do.** Use `model`, or do not branch on the method inside a `conditional`-wrapped action.

**Why it works this way.** `Conditional::handle()` sets `$request->setMethod('GET')` around `$next()` when the strategy has not already produced a validator, because `Router::runRouteWithinStack()`'s pipeline destination calls `prepareResponse()` (Router.php:821) *before* route middleware regains control, and Symfony's `Response::prepare()` nulls the body for a `HEAD` there — leaving nothing to hash. The method is restored in a `finally`, and the response body is emptied again on the way out.

Under a request-derived strategy such as `model` there is no body to hash and nothing is mutated. Under kernel-global placement the mutation is skipped entirely, because changing the method before routing would change which route the request matches — the cost there is an untagged `HEAD`, which is [H12](#h12).

## Symptom index

| What you are seeing | Start here |
| --- | --- |
| `304` works locally and never in production | [H8](#h8), [H12](#h12) |
| No `ETag` on a route that should have one | [H11](#h11), [H16](#h16), [H3](#h3), and the size ceiling in [configuration.md](configuration.md#reference) |
| The controller runs on every request under `conditional:model` | [H11](#h11) |
| A client sees stale content behind a `304` | [H3](#h3), [H5](#h5), [H7](#h7), [H4](#h4), [H17](#h17) |
| A client keeps reading a record after you revoked its access | [H1](#h1) |
| A cached page's `Cache-Control` changed on its own | [H6](#h6), [H12](#h12) |
| Every conditional route returns `500` | [H13](#h13) |
| Every guarded write returns `412` | see [writes.md](writes.md#requirements-and-caveats-for-guarded-routes) — ordering, or a model with no version |
| A guarded write returns `503` | [H9](#h9) |
| A queued job ran against data the request had not committed | [H9](#h9) |
| A request timer or metric reports nonsense on a `304` | [H2](#h2) |
