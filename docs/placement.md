# Placement and ordering

## The rule

`conditional` goes **after** everything that can reject the request, and **before** nothing that changes the response.

Inside the `web` or `api` middleware group both halves are already true for the common case, because `SubstituteBindings` is priority-listed ahead of it and route middleware run inside global middleware. Every problem on this page is a departure from one half or the other.

## Route and group placement

The supported default, and the one everything else is measured against:

```php
Route::get('/articles/{article}', ShowArticle::class)
    ->middleware('conditional:model');
```

```php
Route::middleware(['auth:sanctum', 'conditional:model'])->group(function (): void {
    // …
});
```

`SubstituteBindings` is on Laravel's `$middlewarePriority` list and `conditional` is not, so inside a group the bindings are substituted before `conditional` runs. That is structural rather than lucky: `SortedMiddleware` reorders the priority entries among themselves and leaves an unlisted one where the route put it, so as long as `conditional` is written after the group's own middleware the model has resolved by the time it runs. The model resolves, and the pre-controller short-circuit is available.

## Kernel-global placement

Supported, and it costs four things — all of them silent, none of them errors:

- **No pre-controller short-circuit, ever.** `conditional` runs ahead of `SubstituteBindings`, so `model` finds no bound record. The tag is still attached on the way out, so `304` still happens; the compute saving does not.
- **`HEAD` requests under `body` go untagged**, because the `HEAD`-to-`GET` mutation is deliberately withheld until a route exists.
- **Route-name exclusions are ignored on the write path.** URI patterns still work.
- **A global instance adds no write guard of its own**, because it cannot read a route's flags before routing.

And one that is not silent at all: **with `strategy => 'model'` in the config, a kernel-global `conditional` refuses every write that carries an `If-Match` with `412`, while every write that carries nothing succeeds.** The deferral in the fourth bullet only applies to a strategy that cannot answer before the controller; `model` can, so the guard runs against a route that has not been resolved and a record it cannot reach. Leave `strategy` at `body` when placing `conditional` globally, and name `conditional:model` on the routes that want it.

All five are [H12](hazards.md#h12).

One thing global placement gets right, and route placement never needs: `Conditional::excluded()` is re-checked **after** `$next()`, precisely because `Request::routeIs()` is always false before routing. That second check is why a route-name exclusion is honoured on the read path under global placement at all.

## What must run before `conditional`

`SubstituteBindings`, and everything that can reject the request: `Authorize` / `can:`, `signed`, subscription and feature gates, tenant resolution, any custom rate limiter.

`conditional` must run **after** route model binding. Inside the `api` or `web` middleware group that is already true, since `SubstituteBindings` belongs to both. On a route that has not had its bindings substituted yet — kernel-global placement, or a hand-written middleware list that puts `conditional` first — the strategy finds no record before the controller runs and the request quietly takes the ordinary path: the `ETag` is still attached on the way out, but the controller runs and nothing is saved.

| If it runs after `conditional` | On a short-circuited `304` |
| --- | --- |
| `SubstituteBindings` | no model, no short-circuit; the tag is still attached on the way out and the controller still runs |
| anything that rejects | it never runs — [H1](hazards.md#h1) |
| a terminable middleware | its `terminate()` runs anyway, its `handle()` does not — [H2](hazards.md#h2) |
| anything that sets an `ETag` | `conditional` declines and the route silently loses `304` handling — [H11](hazards.md#h11) |

On the read path a wrong ordering costs the compute saving. On the **write** path it stops writes: `conditional:required` that cannot see the record refuses every `If-Match` with `412`, and every `If-None-Match: *` too. See [the caveats for guarded routes](writes.md#requirements-and-caveats-for-guarded-routes).

One exception is worth knowing. `ThrottleRequests` **cannot** end up after `conditional` in a way that matters: it is sorted ahead of `SubstituteBindings` on the priority list, and the short-circuit needs the bindings — so any arrangement in which the short-circuit can fire is one where throttling has already run. A custom limiter that is not on the priority list has no such protection.

## What must run inside `conditional`

Anything that changes the response body. Headers you need on a `304` are the
exception, and the direction is the opposite one — see below.

A validator identifies one specific set of bytes, and this middleware computes it from the bytes it can see, where it sits. Anything that rewrites the body *after* that — an HTML minifier, a CSP-nonce injector, a CSRF token refresher, a debug bar, a response filter of any kind — leaves the tag describing bytes the client never received. Global middleware always run outside route middleware, so a global rewriter is always in that position; a route middleware declared before `conditional` is too.

```http
ETag: "d5bdba419a6ee56156a6005f54f6b73f"    nonce="ee6508f9fd94"
ETag: "d5bdba419a6ee56156a6005f54f6b73f"    nonce="d2e555ab4bb1"
ETag: "d5bdba419a6ee56156a6005f54f6b73f"    nonce="f73849aac055"
```

One strong tag, three different bodies. A client revalidating with it is told `304` every time, so it keeps the first nonce forever while the server goes on minting new ones — a CSP nonce frozen, or a stale CSRF token in every form on the page. That is [H7](hazards.md#h7).

The ordering is easy to get backwards, so here it is both ways:

```php
// Right: the mutator runs inside, so conditional hashes what the client gets.
->middleware(['conditional', 'inject-nonce'])

// Wrong: conditional hashes the pre-mutation bytes.
->middleware(['inject-nonce', 'conditional'])
```

Under `model` the ordering does not help, because that tag never described the bytes to begin with: a per-response nonce or token is exactly what [the scoping section](reads.md#what-a-model-derived-tag-is-scoped-to) says has to be folded into the tag by hand, or kept off a conditional route.

A `Cache-Control` policy is the exception to everything above, and it inverts
the rule. Its failure is worse than a missing header — a cache adopts what the
short-circuited `304` carries instead of keeping what it had. That is
[H6](hazards.md#h6), and the remedy there is **outside**, not inside: a
short-circuited `304` returns before `$next($request)`, so a middleware declared
after `conditional` never runs and cannot set anything at all.

One nuance, or this page misleads. On a `304` decided **after** the controller
ran, middleware that sets headers unconditionally on the way out survives from
either position, and it is specifically middleware that skips empty responses —
Laravel's own `SetCacheHeaders` among them — that loses them. On a
**short-circuited** `304` position decides nothing, because only the outside
middleware runs; an unconditional outside one is the only thing that can put the
header back.

## Terminable middleware

Declare terminable middleware **outside** `conditional`.

`Kernel::terminateMiddleware()` calls `terminate()` on every middleware the route gathered, whether or not its `handle()` ever ran. On a short-circuited `304` a terminable middleware declared after `conditional` therefore gets its teardown with none of the state its setup would have created — a timer subtracting from `null`, a span closed that was never opened. Under Octane the leftover state is not `null` but the previous request's. Nothing in this package can change that; the position is the remedy. See [H2](hazards.md#h2).

## Reverse proxies

A validator identifies one specific set of bytes, and a reverse proxy that compresses your response changes those bytes. nginx drops the `ETag` outright when it gzips; Apache appends `-gzip` to it. Either way the tag the client holds is not the tag this middleware computed, so `If-None-Match` stops matching and you never see a `304`.

If `304`s work in local development and never in production, check the proxy first — `curl -sI -H 'Accept-Encoding: gzip' <url>` against the proxy and against the app directly will show the difference immediately. See [H8](hazards.md#h8).
