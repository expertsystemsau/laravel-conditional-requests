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
| `Validators/BodyHashStrategy` | Post-controller. `xxh128` over rendered content. Strong. |
| `Validators/ModelStrategy` | Pre-controller. From a route-bound model. Strong. |
| `Preconditions/PreconditionEvaluator` | Our own `If-Match` / `If-Unmodified-Since` evaluation, strong comparison. |
| `Contracts/ProvidesConditionalValidator` | Model-side contract. |
| `Concerns/HasConditionalValidator` | Default trait implementation from `updated_at` + key. |
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
if strategy is model and route has a bound model implementing the contract:
    validator ← model validator
    stub response with validator
    if stub->isNotModified(request)          → return 304, controller never runs   [D3]
response ← $next(request)
if response not 2xx                          → return unchanged
if streamed, binary, or already has an ETag  → return unchanged
if content length > configured max           → return unchanged
validator ← strategy validator from response
response->setEtag(...) / setLastModified(...)
response->isNotModified(request)             → Symfony converts to a compliant 304
return response
```

### 5.4 Write path

```
if method safe (GET/HEAD/OPTIONS/TRACE)      → read path
current ← model strategy validator for the bound resource
ifMatch ← request header If-Match
if ifMatch absent:
    if required                              → throw 428
    else                                     → proceed unguarded
if ifMatch is '*':
    resource exists ? proceed : throw 412
if no STRONG match against current           → throw 412
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

`If-Unmodified-Since` is evaluated only when `If-Match` is absent, mirroring the RFC precedence rule Symfony applies on the read side.

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
    'max_response_bytes' => 1_048_576,
    'methods' => ['GET', 'HEAD'],    // read path eligibility
    'exclude' => [],                 // route names or URI patterns
    'lock' => ['enabled' => false, 'timeout' => 5],
];
```

`xxh128` over `sha256`: this is a change-detection fingerprint, not a security primitive, and xxh128 is substantially faster on large payloads. Collision risk is irrelevant at this scale.

## 7. Edge cases to cover

- `HEAD` requests must produce the same validator as the equivalent `GET`.
- Responses already carrying an ETag (set by the application) are left alone.
- `StreamedResponse` and `BinaryFileResponse` are skipped — hashing them means buffering them.
- Empty-body 2xx responses (`204`) get no validator.
- Multiple ETags in `If-Match` — any strong match passes.
- Malformed validator headers are ignored, not fatal.
- `Vary` interaction: content negotiation changes the representation and therefore the validator.
- Error responses (4xx/5xx) never receive a validator.
- Laravel Octane: no static or container-singleton validator state; everything resolved per request.

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

| Version | Scope |
| --- | --- |
| `v0.1` | Read path, body hash, `If-None-Match` → 304 |
| `v0.2` | Write path, `If-Match` → 412, `required` → 428 |
| `v0.3` | `Last-Modified` / `If-Modified-Since` / `If-Unmodified-Since` |
| `v0.4` | Model-derived validators and pre-controller short-circuit |
| `v0.5` | `lock` mode with in-transaction re-evaluation |
| `v1.0` | Documentation, `werk365/etagconditionals` migration guide, API freeze |

## 10. Non-goals

- `Cache-Control` / `max-age` management — a separate concern with existing packages.
- Server-side response caching — this package validates, it does not store.
- `Range` / `If-Range` — rare in Laravel APIs; revisit only on demand.
- Client-side HTTP caching helpers.
