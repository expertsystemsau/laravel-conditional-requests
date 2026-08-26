---
name: laravel-conditional-requests-development
description: >
  Add HTTP conditional request handling to a Laravel application with
  expertsystemsau/laravel-conditional-requests — body-hash and model-derived ETag
  validators, 304 Not Modified responses on reads, and If-Match lost-update
  protection on writes (412 Precondition Failed / 428 Precondition Required).
license: MIT
metadata:
  author: Mitchell Williams
---

# Laravel Conditional Requests

Use this skill when a Laravel application needs conditional request handling: returning `304 Not Modified` for unchanged resources, or refusing writes that are based on a stale version of a record.

## Primary Goal

- apply the `expertsystemsau/laravel-conditional-requests` package's public API in the smallest correct way

## Status

`v0.3` is shipped and tagged. The read path is implemented: `body` (the default, hashing the rendered response) and `model` (deriving a strong `ETag` from a route-bound record's own version) validator strategies, `304 Not Modified` short-circuiting on `If-None-Match`, and — for a strategy that can answer before the controller runs, `model` included — a pre-controller `304` short-circuit that skips the controller entirely. The write path is implemented: `If-Match` refuses a stale write with `412 Precondition Failed`; `conditional:required` additionally refuses a write carrying no precondition at all with `428 Precondition Required`; and `If-None-Match: *` guards a create, so two clients racing to create the same resource produce one success and one `412`.

Not implemented: `If-Unmodified-Since` and the rest of the `Last-Modified` family, and `lock` mode — the flag parses without error but does nothing yet. Do not generate integration code promising either of those.

> [!WARNING]
> A pre-controller `304` short-circuit runs before anything declared after `conditional` — `can:`, `signed`, subscription and feature gates, and any authorization check inside the controller action included. Always place `conditional` after every middleware that can reject the request.

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
