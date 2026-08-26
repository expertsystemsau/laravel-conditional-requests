# Release Notes

All notable changes to `laravel-conditional-requests` are documented in this file.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/expertsystemsau/laravel-conditional-requests/compare/v0.1.0...main)

### Added

- `model` validator strategy, deriving a strong `ETag` from a route-bound record's own version.
- `ProvidesConditionalValidator` contract and `HasConditionalValidator` trait for models.
- `RequestValidatorStrategy` contract, for strategies that can answer before the controller runs.
- Pre-controller `304` short-circuit: a matching `If-None-Match` on a model-derived route never executes the route action.
- Order-independent middleware flags, with `required` and `lock` reserved and implying the `model` strategy.
- Write path: `If-Match` evaluated with strong comparison before the controller runs, refusing a stale write with `412 Precondition Failed`.
- `If-None-Match: *` create guard, so two clients racing to create the same resource produce one success and one `412`.
- `required` flag, answering an unsafe request that carries no precondition with `428 Precondition Required`.
- `PreconditionEvaluator`, implementing the RFC 9110 §13 comparisons HttpFoundation does not have.
- `PreconditionFailedException` and `PreconditionRequiredException`, rendered through the application's own exception handler.
- A `LogicException` naming the offending configuration when `required` is paired with weak validators or with a strategy that cannot answer before the controller runs.
- Real `412` / `428` copy in `lang/en/messages.php`, publishable with the `laravel-conditional-requests-lang` tag.

### Changed

- `If-None-Match: *` no longer takes the pre-controller short-circuit. A bare wildcard matches every validator there is, so answering it early confirmed a record to a client holding no tag and cleared by nothing declared after `conditional` — behind an authorization gate in that position, a working existence oracle. Such a request now takes the ordinary path and its `304` is decided after the controller, exactly as under `body`. A wildcard accompanied by a tag that does match still short-circuits.
- Streamed, binary, and oversized responses now carry a validator when the strategy has already derived one from the request rather than from the body.
- A `HEAD` request is no longer presented to the controller as a `GET` when the strategy does not need the rendered body.
- Every model-derived `ETag` value changes: the connection's database name and table prefix are now part of the fingerprint, so the same key and version cannot collide across the tenants of a database-per-tenant or prefix-per-tenant deployment. No released version emitted a model-derived tag, so no released baseline is invalidated, but a dev checkout tracking `main` will see every one of its tags change at once and miss on the first request after upgrading.
- A misconfigured `hash` value now fails on every eligible request rather than only on those that reached the tagging step. The strategy is constructed before `$next()` so the short-circuit can consult it, and `BodyHashStrategy` validates the algorithm in its constructor — so a request that ends in a `404`, a stream, or an oversized body now surfaces the same misconfiguration those requests used to pass over. Failing fast is the better behaviour, but it is a behaviour change.
- Unsafe methods take the write path instead of falling through untouched. The `methods` config key governs read-path eligibility only; the write path applies to every unsafe method.
- An unsafe method listed in `methods` no longer receives a validator. The write branch sits ahead of read-path eligibility, so an operator who added `POST` to that key under `v0.1` or `v0.2` to get an `ETag` on a `POST` response silently stops getting one. Nothing else about those responses changes.
- A blank `If-Match` is refused with `412` rather than treated as absent. The realistic shape is a client templating `If-Match: ${etag}` with an empty variable: the header is present and carries zero valid members, so collapsing it to "absent" let the write through unguarded on a route without `required` and clobbered the record. `If-Match: ,` was already `412` and is the same state.
- `If-None-Match: *` now fails **closed**. The create guard used to read a null validator as "the resource is absent", which is one of three states a null collapses — absent, present but yielding no validator, and nothing routed yet — so a `PUT` with `If-None-Match: *` silently overwrote a live record whose version columns were empty, and did the same on any route where the guard ran before `SubstituteBindings`. That made the README's claim that a misordered guard "stops writes" false: it converted the route into one where the create guard overwrote. The write path now asks the strategy whether the target exists, separately from asking for its version, and writes only on a definite *no*. `If-Match: *` is unchanged — it was already fail-closed.
- **`RequestValidatorStrategy` gains a required `targetExists(Request $request): ?bool` method.** A custom strategy implementing that contract must add it: return `true` when the addressed resource is there, `false` when it definitely is not, and `null` when it cannot tell. It has no default — a default that guessed would be the fail-open behaviour above, and one that guessed the other way would refuse every legitimate create. `null` fails the create guard closed. The read path never calls it. Nothing is tagged yet and `ModelStrategy` is the only implementer in the package, so no released API is broken.
- The weak-validator `LogicException` is no longer gated on the `required` flag. It is now raised whenever an `If-Match` would be evaluated against a weak current validator, and still on any guarded request to a `required` route. `weak => true` does not merely disable the guard on a route without the flag, it inverts it: every client sending the correct strong token was refused with `412` while every client sending nothing wrote freely, and nothing in either response said why. **A `conditional:model` write route running under `weak => true` now throws where it previously answered `412`.** A write carrying no precondition on such a route still passes, and `If-None-Match` — compared weakly under §13.1.2 — is unaffected.
- A write carrying a precondition the route's strategy cannot evaluate is refused with `412` instead of passing through untouched. `body` is the default strategy and describes a response that does not exist yet, so a route carrying plain `conditional` had no write guard at all — and a client sending a correct `If-Match` got a `200` with no signal that its optimistic-concurrency check had been discarded. **A plain `conditional` write route whose clients send `If-Match` now returns `412` where it previously ignored the header.** A write carrying no precondition still passes through untouched, so the guard remains opt-in; name `conditional:model` or `conditional:required` to guard those writes properly.
- A concrete `If-None-Match` no longer satisfies the `required` flag. `required` is now satisfied only by an `If-Match`, whatever it names, or by `If-None-Match: *`; a concrete `If-None-Match` on a guarded route is answered `428` whatever it names, including a tag that matches — which was `412`. Without this the flag was defeated by one header: the stale tag `If-Match` correctly refuses applied the write verbatim once moved to `If-None-Match`, as did `"0"`, `garbage`, `W/`, and every other value a client could type, on `POST`, `PUT`, `PATCH`, and `DELETE` alike, and on the create guard too. The comparison semantics are untouched — on a route without `required`, RFC 9110 §13.2.2 still has a non-matching `If-None-Match` proceed.
- `Validator` now rejects an entity tag containing a comma, throwing `InvalidArgumentException` from its constructor alongside the existing empty, double-quote, and control-character rejections. A comma is legal `etagc` but splits the tag across two members of an `If-Match` list, neither of which can ever match — a permanent `412` on that resource. The package's own strategies emit hex and cannot reach it; a custom `ValidatorStrategy` handing a raw column value straight to `Validator` can, and now fails loudly instead of emitting an unusable tag.

### Fixed

- A `304` the middleware produces is now prepared, so it leaves without a `Content-Type`. Symfony's `Response::prepare()` clears PHP's `default_mimetype` for an empty response, and that is what stops the SAPI adding one; under route or group placement `Router::prepareResponse()` ran afterwards and did it, but under kernel-global placement nothing re-prepared and the `304` went out as `text/html; charset=UTF-8`. RFC 9111 §4.3.4 has a cache update its stored headers from the `304`, so a client holding an `application/json` entry had its stored content type overwritten on the first successful revalidation.
- The `HEAD`-to-`GET` mutation no longer happens before the request has been routed. Under kernel-global placement it landed ahead of the router, which then went looking for a `GET` route: a route registered for `HEAD` alone answered `405`, and a `HEAD` to a URI carrying both a `GET` and a `HEAD` action reached the `GET` one. A `HEAD` at that position now goes untagged instead — the router empties its body before the middleware can hash it — which is the same degradation the `model` strategy already takes there, and preferable to a middleware quietly changing what a request routes to.

## v0.1.0

### Added

- `conditional` route middleware attaching an `ETag` to eligible responses.
- `304 Not Modified` short-circuiting on `If-None-Match`, including tag lists and the bare `*` wildcard.
- Swappable validator strategies with a `body` hash default, registered through `ConditionalRequests::extend()`.
- Configurable hash algorithm, weak validators, eligible methods, response size ceiling, and route exclusions.
- Eligibility rules skipping streamed, binary, unsuccessful, oversized, and already-tagged responses.

### Notes

- The write path, the `Last-Modified` family, model-derived validators, and locking are not implemented yet.
