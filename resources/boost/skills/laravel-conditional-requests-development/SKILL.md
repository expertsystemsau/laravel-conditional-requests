---
name: laravel-conditional-requests-development
description: >
  Add HTTP conditional request handling to a Laravel application with
  expertsystemsau/laravel-conditional-requests — ETag and Last-Modified validators,
  304 Not Modified responses on reads, and If-Match lost-update protection on writes
  (412 Precondition Failed / 428 Precondition Required).
license: MIT
metadata:
  author: Mitchell Williams
---

# Laravel Conditional Requests

Use this skill when a Laravel application needs conditional request handling: returning `304 Not Modified` for unchanged resources, or refusing writes that are based on a stale version of a record.

## Primary Goal

- apply the `expertsystemsau/laravel-conditional-requests` package's public API in the smallest correct way

## Status

The package is pre-release. The middleware below is the design contract for `v1.0.0` and is not implemented yet — do not generate integration code against it until the package ships a tagged release.

## Workflow

### 1. Inspect the Laravel app context

- confirm the app is a Laravel project on PHP 8.3+ and Laravel 12+
- identify the routes at issue and which half of the problem applies:
  - **reads** — a GET endpoint whose payload is expensive to serialize or send
  - **writes** — a PATCH/PUT/DELETE endpoint where two clients could overwrite each other

### 2. Apply the package's public API

Attach the middleware to routes rather than globally, so validator generation is opt-in per endpoint.

```php
// Conditional reads: attach a validator, short-circuit matches to 304.
Route::get('/articles/{article}', ShowArticle::class)
    ->middleware('conditional');

// Conditional writes: require If-Match, reject stale (412) and missing (428).
Route::patch('/articles/{article}', UpdateArticle::class)
    ->middleware('conditional:required');
```

### 3. Verify

- a repeated GET carrying `If-None-Match` returns `304` with an empty body
- a PATCH carrying a stale `If-Match` returns `412`
- a PATCH with no `If-Match` returns `428` on `conditional:required` routes

## Rules, References, and Templates

Read before executing:

- [RFC 9110 §13 — Conditional Requests](https://www.rfc-editor.org/rfc/rfc9110#section-13)
- the package README for the current roadmap and header reference table

## Examples

A JSON API serves `GET /articles/{article}` to a mobile client that polls for updates. Adding `->middleware('conditional')` means unchanged articles come back as `304` with no body, cutting both serialization cost and payload size. The same API exposes `PATCH /articles/{article}` to multiple editors; adding `->middleware('conditional:required')` forces each editor to declare the version they read, so the second concurrent save is rejected with `412` instead of silently discarding the first.

## Anti-patterns

- do not apply the middleware globally; validator generation costs work on every response
- do not use `conditional` where `conditional:required` is needed — without `428`, a client can omit `If-Match` and bypass lost-update protection entirely
- do not hand-roll `304` responses alongside the middleware; let one layer own the validator
- do not document package internals here; keep the skill focused on adoption in Laravel apps
