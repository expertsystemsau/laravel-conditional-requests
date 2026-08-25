# Design: HTTP Conditional Requests for Laravel

- **Package:** `expertsystemsau/laravel-conditional-requests`
- **Date:** 2026-08-25
- **Status:** Approved — ready for implementation planning
- **Target:** PHP 8.3–8.5, Laravel 12.x–13.x

## 1. Problem

[RFC 9110 §13](https://www.rfc-editor.org/rfc/rfc9110#section-13) defines conditional requests: a client and server agree on *which version* of a resource they are discussing before either acts. ETag and `Last-Modified` are the two validators underneath; the conditional request is the feature.

Two distinct problems share the handshake:

**Reads.** A client already holds version `v7`. If nothing changed, it should receive an empty `304 Not Modified` rather than the payload again.

**Writes.** A client read `v7`, edited it, and is sending it back. If someone else committed `v8` in the interim, the write must be refused. This is the lost update problem; `If-Match` is the fix and `428 Precondition Required` is what stops clients from opting out of it.

Existing Laravel packages address the read half, via ETag only. The write half is where the value is.

## 2. What the framework already provides

Verified against `symfony/http-foundation` as vendored in this package.

`Response::isNotModified(Request $request)` (Response.php:1118) already implements the **entire read-path comparison**:

- `If-None-Match` with weak comparison semantics and `*` support
- `If-Modified-Since` via `Last-Modified`
- The RFC precedence rule that `If-None-Match` takes priority when both are present
- Method gating through `Request::isMethodCacheable()`

On a match it calls `setNotModified()` (Response.php:1051), which sets `304`, nulls the content, and strips the headers that must not appear on a 304.

`Response::setEtag()` (Response.php:954) handles quoting and the `W/` prefix.

**We do not reimplement any of this.** The read-path middleware generates a validator and delegates.

Conversely, `If-Match` and `If-Unmodified-Since` appear **nowhere** in HttpFoundation — `Request::getETags()` (Request.php:1627) reads `If-None-Match` exclusively. Status codes `412` and `428` have no handling. The write path is entirely ours.

This asymmetry defines the package: a thin read-path adapter over Symfony, and a real write-path implementation beside it.

## 3. Approved decisions

| # | Decision | Choice | Rationale |
| --- | --- | --- | --- |
| D1 | Write-path race handling | Optional locking mode | `If-Match` alone is check-then-write. Default to evaluation only; offer opt-in transaction + row lock. Honest about the window rather than silently racy or invasively transactional. |
| D2 | Default validator strategy | Body hash default, model opt-in | Zero-config drop-in on any route; matches `werk365/etagconditionals` so migration is one line. Model-derived is the power feature, opt-in. |
| D3 | Read-path short-circuit | Yes, when model-derived | With a route-bound model the validator is known before the controller runs, so a `304` can skip it entirely. Turns a bandwidth saving into a compute saving. |

## 4. Correction to the initial sketch: strong vs weak comparison

The pre-approval sketch proposed weak ETags for model-derived validators. That is wrong for the write path and must not be implemented that way.

RFC 9110 §13.1.1 requires **strong comparison** for `If-Match`; §13.1.2 permits **weak comparison** for `If-None-Match`. A weak validator can therefore never satisfy an `If-Match` precondition. A weak model-derived ETag would make `conditional:required` reject every write with `412`.

**Resolution:** model-derived validators are **strong** by default. This carries an assumption — a strong ETag asserts byte-identical representation, so if one resource is served in multiple representations (varying `Accept`, sparse fieldsets, include parameters), the validator must incorporate that variance or the guarantee is unsound.

Therefore:

- The model strategy exposes a hook for folding representation-affecting inputs into the validator.
- Config offers `weak => true` for read-only routes where the weaker guarantee is acceptable.
- Attaching `required` to a route whose strategy yields weak validators is a **configuration error, detected and thrown at boot**, not a silent `412` at runtime.

## 5. Architecture

### 5.1 Components

| Component | Responsibility |
| --- | --- |
| `Http/Middleware/Conditional` | Single entry point. Dispatches on request method to read or write path. |
| `Validators/Validator` | Value object: `etag`, `weak`, `lastModified`. |
| `Validators/ValidatorStrategy` | Contract. Resolves a `Validator` from a request and/or response. |
| `Contracts/RequestValidatorStrategy` | Contract, extends the above. Resolves a `Validator` from the request alone, which is what makes the pre-controller short-circuit possible. Shipped in v0.2; `ModelStrategy` is the only built-in implementation. |
| `Validators/BodyHashStrategy` | Post-controller. `xxh128` over rendered content. Strong. |
| `Validators/ModelStrategy` | Pre-controller. From a route-bound model. Strong. |
| `Preconditions/PreconditionEvaluator` | Our own `If-Match` / `If-Unmodified-Since` evaluation, strong comparison. |
| `Contracts/ProvidesConditionalValidator` | Model-side contract. |
| `Concerns/HasConditionalValidator` | Default trait implementation from the table, key, and either a `version` column or `updated_at`. |
| `Exceptions/PreconditionFailedException` | `HttpException` subclass, 412. |
| `Exceptions/PreconditionRequiredException` | `HttpException` subclass, 428. |

Exceptions extend `Symfony\Component\HttpKernel\Exception\HttpException` so applications customise rendering through their existing exception handler rather than a package-specific hook.

### 5.2 Middleware parameters

Flags, order-independent, comma-separated:

```php
->middleware('conditional')                  // read path, body hash
->middleware('conditional:model')            // read path, model-derived + short-circuit
->middleware('conditional:required')         // write path, If-Match enforced, 412/428
->middleware('conditional:required,lock')    // as above, inside a locked transaction
```

`required` and `lock` imply `model` — the write path cannot use a body hash, because the current validator must be known *before* the controller runs.

### 5.3 Read path

```
if method not cacheable (GET/HEAD)          → fall through to write path
if strategy implements RequestValidatorStrategy:
    validator ← strategy->fromRequest(request)     # null when it cannot answer
    if validator:
        stub response with validator
        if stub->isNotModified(request)      → return 304, controller never runs   [D3]
response ← $next(request)
if response not 2xx, or already has an ETag  → return unchanged
if no validator yet:                             # then it has to come from the body
    if streamed or binary                    → return unchanged
    if content length > configured max       → return unchanged
    validator ← strategy->fromResponse(request, response)
    if no validator                          → return unchanged
response->setEtag(...) / setLastModified(...)
response->isNotModified(request)             → Symfony converts to a compliant 304
return response
```

The body-shaped skips are conditional on *not already holding* a validator, rather than on the strategy's interface. They exist only to avoid reading a body; a validator produced by `fromRequest()` demonstrably did not read one, so it clears them. A strategy that implements `RequestValidatorStrategy` but declines on this request has produced nothing, so `fromResponse()` faces them in full.

### 5.4 Write path

Applies to all unsafe methods — `POST`, `PUT`, `PATCH`, `DELETE`. MDN's canonical mid-air-collision example is a wiki save over `POST`, so restricting this to `PATCH` would miss the documented use case.

```
if method safe (GET/HEAD/OPTIONS/TRACE)      → read path
current ← model strategy validator for the bound resource (null if absent)

# Update guard — If-Match
ifMatch ← request header If-Match
if ifMatch present:
    if ifMatch is '*'  → resource exists ? proceed : throw 412
    if no STRONG match against current       → throw 412

# Create guard — If-None-Match: *   (RFC 9110 §13.1.2, MDN "first upload")
elseif ifNoneMatch is '*':
    resource exists                          → throw 412
    else                                     → proceed

# Fallback validator
elseif ifUnmodifiedSince present:
    resource modified since that date        → throw 412

else:
    if required                              → throw 428
    else                                     → proceed unguarded

if lock:
    DB::transaction(function () {
        re-read the model with lockForUpdate()
        re-evaluate the precondition inside the lock   // closes the TOCTOU window
        return $next(request)
    })
else:
    return $next(request)
```

The re-evaluation inside the lock is the point of `lock` mode. Acquiring a lock without re-checking preserves the race.

**`If-None-Match: *` guards creates.** It succeeds only when the resource does *not* already exist, which makes a create idempotent under concurrency — two clients racing to create the same resource produce one `201` and one `412` instead of a silent duplicate or overwrite. This is the same optimistic-locking idea applied to first upload, and it is a distinct code path from the `If-Match` update guard.

Precedence follows RFC 9110 §13.2.2: `If-Match` is evaluated first; `If-Unmodified-Since` is consulted only in its absence. This mirrors the precedence Symfony already applies on the read side.

### 5.5 Transaction ownership under `lock`

`lock` mode wraps user controller code in a transaction, which has consequences the implementation must handle:

- **Nested transactions.** If the controller opens its own transaction, Laravel savepoints it. Acceptable, but must be covered by a test.
- **Queued jobs.** A job dispatched inside the controller runs before commit unless `afterCommit` is set. Documented as a caveat; not something the package can fix.
- **Long transactions.** A slow controller now holds a row lock for its duration. Documented, with a recommendation to keep guarded routes lean.
- **Non-database resources.** `lock` is meaningless without a lockable row. Throw at boot if the strategy cannot supply one.

## 6. Configuration

```php
return [
    'enabled' => true,
    'strategy' => 'body',            // body | model
    'hash' => 'xxh128',              // any algo supported by hash()
    'weak' => false,
    'max_response_bytes' => 1_048_576,  // 0 or negative means unlimited
    'methods' => ['GET', 'HEAD'],    // read path eligibility
    'exclude' => [],                 // route names or URI patterns
    'lock' => ['enabled' => false, 'timeout' => 5],
];
```

`xxh128` over `sha256`: this is a change-detection fingerprint, not a security primitive, and xxh128 is substantially faster on large payloads. Collision risk from *incidental* change is irrelevant at this scale.

The threat model is worth stating, because it is the one case where the default is wrong: xxh128 offers no collision resistance against a **chosen** input. If response bodies carry attacker-influenced content and serving a stale representation matters, a crafted body can be made to collide with an earlier one and suppress the client's refresh. Those deployments should set `hash` to a cryptographic algorithm.

## 7. Edge cases to cover

- `HEAD` requests must produce the same validator as the equivalent `GET`. For the body-hash strategy this requires a workaround: the request method is mutated to `GET` around the controller call and restored afterwards, with the response body re-emptied once the validator is attached. The ordering that forces this is worth stating precisely, because **two** `prepareResponse()` calls fire per request and they justify different halves of the fix:
    - `Router.php:821`, the pipeline destination inside `runRouteWithinStack()`, runs *before* control returns to any route middleware's post-`$next()` code. Symfony's `Response::prepare()` nulls the body for `HEAD` there, so without the method mutation there is nothing left to hash. **This is why the fix is necessary.**
    - `Router.php:799`, the outer call in `runRoute()`, wraps the whole middleware stack and runs last, with the method already restored to `HEAD`. It nulls the body again. **This is why the middleware's own nulling is redundant under route or group placement** — and load-bearing only under global placement, where the method is still `GET` for both calls and nothing downstream re-prepares the response.

    The middleware therefore routes every exit through one place that applies the nulling, rather than relying on the framework to do it. Model-derived validators (`v0.2`) read the record's version instead of the rendered body, so the mutation is skipped for them entirely.
- Responses already carrying an ETag (set by the application) are left alone. The pre-controller short-circuit cannot make this check — the controller has not run, so no application-set ETag exists yet to defer to — which means a client sending a fabricated tag that happens to match the model-derived validator gets a `304` carrying a tag the eventual representation does not actually have. Only reachable with a fabricated tag; no code change, documented here.
- `StreamedResponse` and `BinaryFileResponse` are skipped — hashing them means buffering them.
- Empty-body 2xx responses (`204`) get no validator.
- Multiple ETags in `If-Match` — any strong match passes.
- Malformed validator headers are ignored, not fatal.
- `Vary` interaction: content negotiation changes the representation and therefore the validator.
- Error responses (4xx/5xx) never receive a validator.
- Laravel Octane: no static or container-singleton validator state; everything resolved per request.
- **Wildcards are unquoted.** `If-Match: *` and `If-None-Match: *` are sent bare, not as `"*"`. The parser must strip optional whitespace and match the bare token, and must not confuse it with an entity tag whose value happens to be an asterisk.
- **Content coding changes the entity tag.** MDN notes that reverse proxies alter ETags when they compress a response — Apache appends `-gzip` by default. A body hash computed before a downstream compression layer will not be the tag the client received. Documented, with a recommendation to hash after the response is final and to be aware of proxy-level rewriting.
- Multiple comma-separated entity tags in `If-Match` / `If-None-Match`, with arbitrary surrounding whitespace.
- **The pre-controller short-circuit answers before authorization does.** A matching tag skips everything the controller would have decided, per-record authorization included, so a client holding a still-valid tag keeps getting `304` after its access to that record is revoked, or for a record the controller would otherwise have hidden from it. Inherent to answering before the controller runs, not a defect. Mitigated by documentation: place `conditional` after authorization middleware, and treat a per-record check made inside the controller action itself as skipped on a hit.
- **A short-circuited `304` carries fewer headers than the long way round.** `Vary`, an application `Cache-Control`, `Content-Location`, and similar are set by the controller or by downstream middleware, none of which run on a hit. RFC 9110 §15.4.5 says a `304` should carry them. The `304` produced after the controller has run carries them exactly as before; only the pre-controller short-circuit is short a set of headers nothing ran to set.
- **Route-name exclusions cannot suppress the short-circuit.** `Conditional::excluded()` is re-checked after `$next()` specifically because `Request::routeIs()` is always false before routing, and the short-circuit returns before that re-check ever runs. Unreachable with the shipped `model` strategy — no route bound means `fromRequest()` returns null and the short-circuit never fires — but reachable for a route-free custom `RequestValidatorStrategy` under kernel-global placement.

## 8. Testing

Pest 4/5 with Orchestra Testbench, exercising real routes registered in `workbench/`.

- **Read path:** 200-then-304 cycles for `If-None-Match` and `If-Modified-Since`, precedence when both are sent, `*`, weak/strong comparison, 304 body and header stripping.
- **Write path:** matching / stale / absent / `*` `If-Match`; 412 and 428 status and body shape; `If-Unmodified-Since` fallback and precedence.
- **Short-circuit:** assert via a controller-invocation spy that the controller does **not** run on a model-derived 304.
- **Locking:** concurrent-write simulation proving the unlocked mode can lose an update and the locked mode cannot. This is the test that justifies the feature.
- **Skips:** streamed, binary, oversized, pre-tagged, and error responses.
- **Boot-time errors:** `required` with a weak strategy; `lock` without a lockable resource.
- Type coverage held at 100% per the existing `composer test:types` gate.

## 9. Phasing

**Reordered 2026-08-26.** The original order put the write path at `v0.2` and
model-derived validators at `v0.4`. That is not buildable in that order: the write
path must know the resource's *current* validator **before** the controller runs, and
a body hash cannot supply one — there is no response to hash yet. The write path
therefore depends on the model strategy, and the model strategy has to land first.
Scope is unchanged; only the sequence moved.

| Version | Scope |
| --- | --- |
| `v0.1` | Read path, body hash, `If-None-Match` → 304 — **shipped** |
| `v0.2` | Model-derived validators, `fromRequest()`, pre-controller short-circuit — **shipped** |
| `v0.3` | Write path, `If-Match` → 412, `required` → 428, `If-None-Match: *` create guard |
| `v0.4` | `Last-Modified` / `If-Modified-Since` / `If-Unmodified-Since` |
| `v0.5` | `lock` mode with in-transaction re-evaluation |
| `v1.0` | Documentation, `werk365/etagconditionals` migration guide, API freeze |

## 10. Non-goals

- `Cache-Control` / `max-age` management — a separate concern with existing packages.
- Server-side response caching — this package validates, it does not store.
- `Range` / `If-Range` — rare in Laravel APIs; revisit only on demand.
- Client-side HTTP caching helpers.

## 11. Parity with `werk365/etagconditionals`

Verified by reading the incumbent's source at `365Werk/etagconditionals@main`, not its README. Everything it does, this package must do, and the defects below are the reason for building it.

### 11.1 Feature parity baseline

| Capability | werk365 | This package |
| --- | --- | --- |
| Set `ETag` on responses | `setEtag` middleware, `md5()` of body | Body-hash strategy, `xxh128`, configurable |
| `If-None-Match` → `304` | `ifNoneMatch` middleware | Read path via Symfony `isNotModified()` |
| `If-Match` → `412` | `ifMatch` middleware | Write path, `PreconditionEvaluator` |
| Custom ETag generator | `etagGenerateUsing()` closure | Strategy contract, swappable per route |
| `HEAD` handled as `GET` | Method mutate/restore | Method mutate/restore, required by the same `Response::prepare()` ordering werk365 works around |
| Middleware group alias | `etag` group | `conditional` with flags |
| Octane support | `app()->instance('request', …)` fixup | No static or singleton validator state, and the strategy resolver reads config from the *active* container rather than a boot-time capture, so a `config` entry in `octane.flush` cannot split-brain it |

### 11.2 Defects in the incumbent that we fix

| # | Issue in werk365 | Consequence | Our behaviour |
| --- | --- | --- | --- |
| 1 | `IfMatch` guards `PATCH` only | `PUT`, `POST`, `DELETE` are unprotected. MDN's own wiki example uses `POST`. | All unsafe methods |
| 2 | Returns early when `If-Match` is absent | The guard is opt-out: omit the header, clobber freely. No `428` exists. | `428 Precondition Required` under `required` |
| 3 | `in_array('"*"', $ifMatchArray)` expects a **quoted** wildcard | A spec-compliant `If-Match: *` fails to match and gets a spurious `412` | Bare `*` parsed per RFC |
| 4 | `if_match_weak` defaults to `true`, stripping `W/` | Weak comparison on `If-Match` contradicts RFC 9110 §13.1.1 and MDN, which both require **strong** validation | Strong comparison, not configurable away |
| 5 | Re-dispatches an internal `GET` through `app()->handle()` to learn current state | Runs the entire kernel a second time per guarded write — middleware, auth, controller, serialization. Copies all request headers into the synthetic GET and needs an `X-From-Middleware` sentinel that consumers must special-case in their own middleware. | Model-derived validator, no second dispatch |
| 6 | No lock between the internal `GET` and `$next()` | TOCTOU: the update it just validated can be superseded before it commits | Optional `lock` mode with in-transaction re-check |
| 7 | Unreachable `if ($ifMatch === null) return 412` | Dead code; the early return above already handled it | N/A |
| 8 | No `Last-Modified`, `If-Modified-Since`, or `If-Unmodified-Since` | Half of RFC 9110's validators unimplemented | All four conditional headers |
| 9 | No `If-None-Match: *` create guard | Concurrent creates silently duplicate or overwrite | Supported |
| 10 | `setEtag` hashes every response | Streamed, binary, and large responses get buffered and hashed | Skip rules with a size ceiling |
| 11 | `md5()` on every response body | Slower than necessary for a non-cryptographic fingerprint | `xxh128` default |
| 12 | Three middleware to wire in the correct combination | Easy to apply `setEtag` without `ifNoneMatch` and get tags that never save a round trip | One middleware, flags |
| 13 | No PHP constraint; Laravel ≤ 12 | Unusable on Laravel 13 | PHP 8.3–8.5, Laravel 12–13 |

### 11.3 Migration differences a `werk365/etagconditionals` user will hit

Read-path *feature* parity is not read-path *behavioural* identity. Three differences are visible on day one of a migration and belong in the `v1.0` migration guide:

| # | Difference | Effect at cutover |
| --- | --- | --- |
| 1 | `max_response_bytes` defaults to 1 MiB; werk365 has no ceiling | Responses over 1 MiB silently stop carrying an `ETag`, so a route that used to `304` no longer does. Raise the ceiling, or set it to `0` for unlimited, to match the incumbent exactly. |
| 2 | Only 2xx responses are tagged; werk365's `setEtag` hashes every response | Clients that were caching against tags on 3xx/4xx/5xx responses lose them. This is deliberate — a validator on an error response is meaningless — but it is a behaviour change, not a no-op. |
| 3 | `xxh128` by default; werk365 uses `md5()` | Every cached tag on every client is invalidated once, at cutover: the first request after deploy is a full `200` even for unchanged content. Setting `hash => 'md5'` preserves existing tags byte-for-byte and avoids the one-time storm. |

Only (3) is fully avoidable by configuration; (1) is avoidable at the cost of the protection it buys, and (2) is not. Parity is this project's acceptance bar, so these are stated rather than glossed.

### 11.4 Beyond parity

- Pre-controller `304` short-circuit on model-derived validators — saves compute, not just bandwidth (D3)
- Optional pessimistic locking that actually closes the lost-update window (D1)
- Boot-time validation: `required` with a weak strategy, or `lock` without a lockable resource, throws instead of failing mysteriously at runtime
- Configurable skip rules — methods, status codes, routes, response size
- Migration guide mapping `setEtag` / `ifNoneMatch` / `ifMatch` onto `conditional` flags
