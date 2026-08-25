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

- Streamed, binary, and oversized responses now carry a validator when the strategy has already derived one from the request rather than from the body.
- A `HEAD` request is no longer presented to the controller as a `GET` when the strategy does not need the rendered body.
- A misconfigured `hash` value now fails on every eligible request rather than only on those that reached the tagging step. The strategy is constructed before `$next()` so the short-circuit can consult it, and `BodyHashStrategy` validates the algorithm in its constructor — so a request that ends in a `404`, a stream, or an oversized body now surfaces the same misconfiguration those requests used to pass over. Failing fast is the better behaviour, but it is a behaviour change.

## v0.1.0

### Added

- `conditional` route middleware attaching an `ETag` to eligible responses.
- `304 Not Modified` short-circuiting on `If-None-Match`, including tag lists and the bare `*` wildcard.
- Swappable validator strategies with a `body` hash default, registered through `ConditionalRequests::extend()`.
- Configurable hash algorithm, weak validators, eligible methods, response size ceiling, and route exclusions.
- Eligibility rules skipping streamed, binary, unsuccessful, oversized, and already-tagged responses.

### Notes

- The write path, the `Last-Modified` family, model-derived validators, and locking are not implemented yet.
