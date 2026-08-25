# Release Notes

All notable changes to `laravel-conditional-requests` are documented in this file.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/expertsystemsau/laravel-conditional-requests/compare/v0.1.0...main)

## v0.1.0

### Added

- `conditional` route middleware attaching an `ETag` to eligible responses.
- `304 Not Modified` short-circuiting on `If-None-Match`, including tag lists and the bare `*` wildcard.
- Swappable validator strategies with a `body` hash default, registered through `ConditionalRequests::extend()`.
- Configurable hash algorithm, weak validators, eligible methods, response size ceiling, and route exclusions.
- Eligibility rules skipping streamed, binary, unsuccessful, oversized, and already-tagged responses.

### Notes

- The write path, the `Last-Modified` family, model-derived validators, and locking are not implemented yet.
