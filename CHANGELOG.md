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

### Changed

- `If-None-Match: *` no longer takes the pre-controller short-circuit. A bare wildcard matches every validator there is, so answering it early confirmed a record to a client holding no tag and cleared by nothing declared after `conditional` — behind an authorization gate in that position, a working existence oracle. Such a request now takes the ordinary path and its `304` is decided after the controller, exactly as under `body`. A wildcard accompanied by a tag that does match still short-circuits.
- Streamed, binary, and oversized responses now carry a validator when the strategy has already derived one from the request rather than from the body.
- A `HEAD` request is no longer presented to the controller as a `GET` when the strategy does not need the rendered body.
- Every model-derived `ETag` value changes: the connection's database name and table prefix are now part of the fingerprint, so the same key and version cannot collide across the tenants of a database-per-tenant or prefix-per-tenant deployment. No released version emitted a model-derived tag, so no released baseline is invalidated, but a dev checkout tracking `main` will see every one of its tags change at once and miss on the first request after upgrading.
- A misconfigured `hash` value now fails on every eligible request rather than only on those that reached the tagging step. The strategy is constructed before `$next()` so the short-circuit can consult it, and `BodyHashStrategy` validates the algorithm in its constructor — so a request that ends in a `404`, a stream, or an oversized body now surfaces the same misconfiguration those requests used to pass over. Failing fast is the better behaviour, but it is a behaviour change.

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
