# Release Notes

All notable changes to `laravel-conditional-requests` are documented in this file.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/expertsystemsau/laravel-conditional-requests/compare/v1.0.1...HEAD)

### Fixed

Documentation only. No behaviour changed, and no published symbol moved — the
v1.0.0 code is unaffected.

- **[H6](docs/hazards.md#h6) prescribed a remedy that cannot run.** It told the
  reader to set `Cache-Control` from middleware declared *inside* `conditional`.
  On the path H6 is about — a `model` route whose cached entry successfully
  revalidates — that middleware never executes: `handle()` returns the
  short-circuited `304` before it calls `$next($request)`, so nothing declared
  after this middleware is entered at all. The advice named the one position
  where the header is guaranteed not to appear.
  
  Position was never the discriminator. The *outside* middleware does run and
  does see the `304` on the way out; Laravel's `SetCacheHeaders` loses the header
  because it returns early on a contentless response, not because of where it
  sits. The remedy is an outside middleware that sets the header
  unconditionally, or keeping a cache-policy route off `model`. Inside remains
  correct for a `304` decided *after* the controller ran — `body`, or `model`
  when `fromRequest()` declined — where `attach()` marks the real response and
  `setNotModified()` leaves `Cache-Control` alone. The two cases are now stated
  separately rather than collapsed into one claim about ordering.
  
- **`docs/placement.md` carried the same inversion twice**, under "What must run
  inside `conditional`" and in its closing nuance. [H7](docs/hazards.md#h7) was
  already correct and says explicitly that ordering does not help under `model`;
  H6 and this page now agree with it.
  
- **`docs/api.md` undercounted the API-freeze examples.** It said "Four further
  examples" in `PublicApiTest.php` and listed four; there are five. The missing
  one freezes the strategy contract inheritance chain, and it is the only thing
  asserting that chain — the signature snapshot records no `extends`
  relationships — for a promise the same page makes a hundred lines earlier.
  

## [v1.0.1](https://github.com/expertsystemsau/laravel-conditional-requests/compare/v1.0.0...v1.0.1) - 2026-08-26

### Fixed

Documentation only. No behaviour changed, and no published symbol moved — the
v1.0.0 code is unaffected.

- **[H6](docs/hazards.md#h6) prescribed a remedy that cannot run.** It told the
  reader to set `Cache-Control` from middleware declared *inside* `conditional`.
  On the path H6 is about — a `model` route whose cached entry successfully
  revalidates — that middleware never executes: `handle()` returns the
  short-circuited `304` before it calls `$next($request)`, so nothing declared
  after this middleware is entered at all. The advice named the one position
  where the header is guaranteed not to appear.
  
  Position was never the discriminator. The *outside* middleware does run and
  does see the `304` on the way out; Laravel's `SetCacheHeaders` loses the header
  because it returns early on a contentless response, not because of where it
  sits. The remedy is an outside middleware that sets the header
  unconditionally, or keeping a cache-policy route off `model`. Inside remains
  correct for a `304` decided *after* the controller ran — `body`, or `model`
  when `fromRequest()` declined — where `attach()` marks the real response and
  `setNotModified()` leaves `Cache-Control` alone. The two cases are now stated
  separately rather than collapsed into one claim about ordering.
  
- **`docs/placement.md` carried the same inversion twice**, under "What must run
  inside `conditional`" and in its closing nuance. [H7](docs/hazards.md#h7) was
  already correct and says explicitly that ordering does not help under `model`;
  H6 and this page now agree with it.
  
- **`docs/api.md` undercounted the API-freeze examples.** It said "Four further
  examples" in `PublicApiTest.php` and listed four; there are five. The missing
  one freezes the strategy contract inheritance chain, and it is the only thing
  asserting that chain — the signature snapshot records no `extends`
  relationships — for a promise the same page makes a hundred lines earlier.
  

## [v1.0.0](https://github.com/expertsystemsau/laravel-conditional-requests/releases/tag/v1.0.0) - 2026-08-26

### Added

**The middleware and its flags**

- `conditional` route middleware, implementing RFC 9110 §13 conditional requests on both the read and the write path.
- Order-independent middleware flags: a strategy name, plus the reserved words `required` and `lock`, both of which imply the `model` strategy because the current validator has to be known before the controller runs.
- `Http\Middleware\Conditional` is registered as the `conditional` alias on every boot, and works as route, group, or kernel-global middleware.

**Validator strategies and the registry**

- `body` strategy: a hash of the rendered response, working on any route with no model changes. Saves bandwidth, not compute.
- `model` strategy: a strong validator derived from the route-bound record's own version — the connection's database name, the connection's table prefix, the table, the key, and either an explicit `version` column or the raw `updated_at` value. Saves bandwidth and compute.
- `ValidatorStrategy`, `RequestValidatorStrategy` and `LockableValidatorStrategy` contracts, so an application can register its own strategy under any name and use it as a middleware flag.
- `ConditionalRequests` strategy registry, resolved from the container and extended from a service provider's `boot()`.
- `ProvidesConditionalValidator` contract and `HasConditionalValidator` trait, with `conditionalVersionColumns()` and `conditionalLastModifiedColumn()` as the documented extension points.
- `Validator` value object: a bare entity tag, a weakness flag, and an optional modification instant floored to the whole second in UTC. It rejects a tag that cannot appear inside a quoted entity tag — empty, or containing a double quote, a control character, or a comma, the last of which would split the tag across two members of an `If-Match` list and pin the resource at `412`.

**The read path**

- `ETag` attached to eligible 2xx responses, and `304 Not Modified` decided by Symfony's own `Response::isNotModified()`, including tag lists and the bare `*` wildcard.
- Pre-controller `304` short-circuit under any strategy that can answer from the request: a matching `If-None-Match` on a model-derived route never executes the route action.
- The short-circuit is refused to a client that has demonstrated nothing. A bare `If-None-Match: *` matches every validator there is, and a lone `If-Modified-Since` can be guessed, so neither is answered before the controller — behind a gate declared after `conditional`, either would have made the status code an existence oracle. Both take the ordinary path and get the same `304` at the end. A wildcard or a date sent *alongside* a tag that matches still short-circuits.
- Eligibility rules skipping unsuccessful, already-tagged, streamed, binary, oversized, and empty-bodied responses — with the streamed, binary and size rules suppressed when the strategy already derived a validator from the request, because that one cost no body read.
- A `304` the middleware produces is prepared, so it leaves without a `Content-Type` of PHP's own choosing under kernel-global placement, where nothing else re-prepares it.
- `HEAD` support: the request is presented to the controller as a `GET` only where a body hash is actually needed and a route has already been resolved, and the response body is emptied again on the way out.

**`Last-Modified`**

- `Last-Modified` on model-derived responses, alongside the `ETag`, with `If-Modified-Since` answered on the read path.
- A date is published only once the second holding the change has elapsed. RFC 9110 §8.8.2.2 permits a date validator to be treated as strong only when the server knows the representation did not change twice inside the second it names, which is unknowable while that second is still running — so a record that has just changed carries its `ETag` alone until the second is over.
- Attaching a date never changes what a response says about caching: Symfony would otherwise recompute an unset `Cache-Control` into one permitting heuristic freshness, and the middleware puts the original back.

**The write path**

- `If-Match` evaluated with strong comparison before the controller runs, refusing a stale write with `412 Precondition Failed` — on **every** unsafe method, not only `PATCH`. A blank `If-Match`, and `If-Match: W/*`, are both refused rather than treated as absent.
- `required` flag, answering an unsafe request that carries no precondition with `428 Precondition Required`. It is satisfied by an `If-Match`, by `If-None-Match: *`, or by a valid `If-Unmodified-Since` — and never by a concrete `If-None-Match`, which states no version the client believes it is writing over.
- `If-None-Match: *` create guard, so two clients racing to create the same resource produce one success and one `412`. It writes only on a *definite* absence, asked of the strategy through `targetExists()` separately from the version, so a strategy that cannot tell fails closed.
- `If-Unmodified-Since` on the write path, at RFC 9110 §13.2.2's precedence position between `If-Match` and `If-None-Match`. A resource that publishes no date refuses it with `412` rather than ignoring it.
- A precondition the route's strategy cannot evaluate is refused with `412` rather than discarded, so a client is never told a guard passed when it was never applied. A kernel-global instance defers that refusal to the route, which is what keeps the guards on the routes underneath it working.
- `PreconditionFailedException` and `PreconditionRequiredException`, both Symfony `HttpException` subclasses rendered through the application's own exception handler, with real copy in `lang/en/messages.php`.

**`lock` mode**

- `lock` middleware flag: a transaction on the target record's own connection, a `SELECT … FOR UPDATE` re-read, and **the precondition evaluated a second time inside the lock** — which is what closes the check-then-write race `If-Match` alone leaves open. Opt-in, per route.
- `lock_timeout` configuration key, applied per request on PostgreSQL (`SET LOCAL lock_timeout`) and MySQL / MariaDB (`SET SESSION innodb_lock_wait_timeout`, restored afterwards), answering an expired wait with `503 Service Unavailable` and a `Retry-After` rather than an opaque `500`.
- `LockTimeoutException`, catchable as Symfony's `ServiceUnavailableHttpException`.
- `composer test:lock` and a `locking` CI workflow, exercising real row-lock contention against MySQL and PostgreSQL.

**Configuration**

- Nine keys: `enabled`, `strategy`, `hash`, `weak`, `last_modified`, `max_response_bytes`, `methods`, `exclude`, and `lock_timeout`. `methods` governs the read path only; `exclude` suppresses both paths and matches route names and URI patterns alike.
- Publishable config, translations, and an assets tag, under `laravel-conditional-requests` and its three per-resource variants.

**Failing loudly on a misconfiguration**

- A `LogicException` naming the offending route or config key when `weak => true` would invert a write guard, when a strategy cannot produce a validator before the controller runs on a `required` route, when `lock` names a strategy that cannot identify a row or a resource that is not one, and when a write route binds more than one record implementing `ProvidesConditionalValidator`.

### Notes

- `lock` is opt-in per route and changes nothing about a route that does not carry it.
- A controller run under `lock` is inside a transaction: a job it dispatches runs before the commit unless `afterCommit` is set, and returning an error response commits rather than rolls back.
- SQLite has no row locks — `lockForUpdate()` compiles to nothing there — so `lock` on SQLite gets the re-read and the re-evaluation without the exclusion. SQL Server locks and re-evaluates correctly but is not sent a lock timeout, so its own default of wait-forever stands whatever `lock_timeout` says.
- A pre-controller `304` answers before anything declared after `conditional`, including per-record authorization. That, and seventeen other things worth knowing before deploying, are in [`docs/hazards.md`](docs/hazards.md).

### Verified for this release

Measured on a clean Laravel 13.29 application that installed the package the way
a stranger would, on PHP 8.5, against MySQL 8.4.11 and PostgreSQL 16.15.

- **Row locks against real databases.** `composer test:lock` — 5 passed, 0
  skipped, on MySQL and again on PostgreSQL. The suite fails rather than skips
  when the driver is absent, so a pass is proof it executed.
- **Concurrency, with the lock.** 240 concurrent `PATCH` requests against a
  `conditional:required,lock` route through nginx + php-fpm on MySQL. Every
  `2xx` was a real serialised increment — the row's final version rose by
  exactly the number of `2xx` responses, every other request was a `412`, and
  there were no lock timeouts and no unexpected statuses.
- **Concurrency, without it.** The same route, the same load, with a deliberate
  250 ms window opened between the guard's read and the controller's write:
  without `lock`, 23 requests were answered `2xx` while the version advanced by
  only 6 — 17 committed writes lost, every one of them past an `If-Match` the
  guard had accepted. With `lock` and the identical window, 6 `2xx`, 24 `412`,
  and the version advanced by exactly 6. That gap is what `lock` is for, and it
  is why `If-Match` alone is documented as check-then-write.
- **The lock timeout does not leak.** Under Octane (FrankenPHP, one worker, one
  persistent MySQL connection held across every request),
  `@@session.innodb_lock_wait_timeout` read 50 before a guarded write, 5 inside
  the guarded transaction, and 50 again afterwards — and 50 again after a write
  refused with `412`. The session is left as it was found.
- **The read path under three SAPIs.** A 49-case matrix — cold `ETag`, `304`
  with a zero-length body and the tag echoed, controller-execution counts,
  `HEAD` parity, the `*` and `W/*` refusals behind a gate declared after
  `conditional`, `428`, `412` on stale, blank, zero-member and weak `If-Match`,
  tag replay, `If-Match: *`, the create guard, and `lock` — passed identically
  under `artisan serve` on SQLite, nginx + php-fpm on MySQL, and Octane on
  MySQL.
- **`Content-Type` on a `304` under php-fpm.** Confirmed absent. The
  middleware prepares the response itself so PHP's `default_mimetype` is
  suppressed; this had previously only been checked on the CLI SAPI.
- **No state bleeding under Octane.** 80 interleaved requests for two records on
  a single worker: each record kept its own tag, one record's tag never produced
  a `304` for the other, and no tag drifted.

### Development history

`v1.0.0` is the first tagged release. The package was built in five phases —
read path, model-derived validators, write path, `Last-Modified`, locking — and
each phase's plan, decisions, and definition of done are in
[`docs/plans/`](docs/plans). None of those phases was ever tagged or published.
