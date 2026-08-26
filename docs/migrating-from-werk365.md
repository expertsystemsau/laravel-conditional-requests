# Migrating from `werk365/etagconditionals`

Every claim on this page about the incumbent was read out of its source — `EtagConditionalsServiceProvider`, `EtagConditionals`, the three middleware, `config/etagconditionals.php`, and its `composer.json` — rather than out of its README.

## Should you migrate?

You should migrate if you need the **write** half. `If-Match` on every unsafe method, `428` so clients cannot opt out by omitting a header, an `If-None-Match: *` create guard, and a `lock` mode that actually closes the check-then-write race are the reasons this package exists. The incumbent's `IfMatch::handle()` returns early unless the request is a `PATCH` *and* carries the header, so it guards one method and only when the client chooses to be guarded, and it has no lock at all.

You should migrate if you need `Last-Modified`, `If-Modified-Since`, or `If-Unmodified-Since`. The incumbent implements none of the three.

You should migrate if the incumbent's internal `GET` re-dispatch is costing you. `IfMatch` builds a second request with `Request::create()`, copies your headers onto it, adds `X-From-Middleware: IfMatch`, and runs it through `app()->handle()` — the entire kernel a second time for every guarded write: middleware, auth, controller, serialization. Your own middleware then has to special-case that sentinel header.

If none of those describe you, read [When not to migrate](#when-not-to-migrate) first.

## When not to migrate

Five cases where the honest answer is don't.

- **You use `setEtag` plus `ifNoneMatch` on a small JSON API, it works, and you have no lost-update problem.** Then don't. You would trade a working three-middleware install for a config file, a one-time cache-invalidation storm across every client, and — if you want any of the write path — a contract and a trait on your models. In exchange you get a 1 MiB size ceiling, skip rules for streamed and binary responses, and a faster hash. Those are real, and they are small. This is the case this guide exists to be honest about.
- **Your models have no column that reliably means "this row changed", and you cannot add one.** The write path is derived from the record's version, not from the response body. Without a `version` column or trustworthy timestamps there is nothing to guard with. See [H3](hazards.md#h3).
- **You are on PHP below 8.3 or Laravel below 12.** The incumbent requires `illuminate/support: ~7|~8|~9|~10` and declares no `php` constraint at all; this package requires `php: ^8.3` and `^12.0||^13.0`. There is no backport.
- **You rely on tags being attached to non-2xx responses.** `SetEtag::handle()` calls `$response->setEtag(...)` unconditionally — every status, every response, and over a tag your application set itself. This package tags 2xx only, deliberately, and it is not configurable.
- **Something downstream treats a change of ETag algorithm as a failure** rather than as a cache miss. Migration invalidates every stored tag once. That is normally a single expensive request per client; if it is not, find out why before you deploy.

Migrating for the read path alone is a lateral move. Migrate for the write path.

## The mechanical mapping

| werk365 | This package | Notes |
| --- | --- | --- |
| `->middleware('etag')` | `->middleware('conditional')` | The group is three middleware (`SetEtag`, `IfNoneMatch`, `IfMatch`); this is one. That consolidation is defect #12 — `setEtag` without `ifNoneMatch` gave you tags that never saved a round trip. |
| `->middleware('setEtag')` | `->middleware('conditional')` | Tagging and `304` handling are not separable here, and separating them was the defect. |
| `->middleware('ifNoneMatch')` | `->middleware('conditional')` | Same middleware. |
| `->middleware('ifMatch')` | `->middleware('conditional')` on the write route, or `conditional:required` | **Not like-for-like.** Theirs is a no-op when the header is absent; `required` answers `428`. Without `required` the closest equivalent is plain `conditional`, which evaluates `If-Match` when it is sent and passes when it is not. |
| `EtagConditionals::etagGenerateUsing($closure)` | a `ValidatorStrategy`, registered with `$conditional->extend('name', …)`, selected with `conditional:name` | Theirs is one global static callback for the whole application; this is named and per-route. The signatures differ too: theirs receives `($request, $response)` and returns a raw string, while `fromResponse(Request, Response): ?Validator` returns a value object and `null` means "leave this response alone" — which theirs has no way to express. |
| `config('etagconditionals.if_none_match_weak')` | nothing to set | Their key exists to strip `W/` from the client's tags before an exact string comparison. Symfony's `Response::isNotModified()` already performs RFC-correct weak comparison for `If-None-Match`, so there is nothing to configure. |
| `config('etagconditionals.if_match_weak')` | **deliberately absent** | Defect #4. RFC 9110 §13.1.1 requires strong comparison for `If-Match`; theirs defaults to `true`, which strips `W/` from the client's tags. Not configurable here — see day-one item 4. |
| `X-From-Middleware: IfMatch` | nothing | There is no internal re-dispatch. Delete the special-casing from your own middleware. |
| Facade alias `EtagConditionals` | none | The strategy registry is injected into a service provider's `boot()`. This package registers no facade and claims no class alias. |
| `vendor:publish --provider="Werk365\EtagConditionals\EtagConditionalsServiceProvider"` | `vendor:publish --tag="laravel-conditional-requests-config"` | |

## A worked cutover

One `apiResource`, the shape the incumbent's own README recommends.

**Before:**

```php
Route::apiResource('articles', ArticleController::class)
    ->middleware('etag');
```

**After:**

```php
Route::apiResource('articles', ArticleController::class)
    ->middleware('conditional');

// …and, once you know which clients send a precondition, per guarded route:
Route::patch('articles/{article}', [ArticleController::class, 'update'])
    ->middleware('conditional:required');
```

The model change is the part a migrator will not expect:

```php
use ExpertSystems\ConditionalRequests\Concerns\HasConditionalValidator;
use ExpertSystems\ConditionalRequests\Contracts\ProvidesConditionalValidator;

class Article extends Model implements ProvidesConditionalValidator
{
    use HasConditionalValidator;
}
```

**This is the real cost of the migration.** Swapping `etag` for `conditional` is one line. Getting the write path is a contract and a trait on every guarded model, plus a `version` column or trustworthy timestamps on each. A migrator who budgets for a middleware rename will be wrong.

The config to set for the closest possible day-one behaviour:

```php
'hash' => 'md5',              // keeps every client's existing tag valid
'max_response_bytes' => 0,    // no ceiling, matching the incumbent
```

`hash('md5', $content)` and `md5($content)` produce the same lowercase hex string, and both packages quote the tag on the way out — theirs with `Str::of($etag)->start('"')->finish('"')`, this one through `Response::setEtag()` — so the wire form is byte-identical and no client sees a change.

## What changes in behaviour on day one

Twelve items, each in the shape *what changes → who it hits → what to do*.

1. **Responses over 1 MiB stop being tagged.** `max_response_bytes` defaults to `1_048_576`; the incumbent has no ceiling. A route that used to `304` silently stops. → Set `max_response_bytes => 0`, or accept it and know which routes are affected.
2. **Only 2xx responses are tagged.** `SetEtag` hashes every response, including 3xx, 4xx and 5xx. → Not avoidable. A validator on an error response is meaningless, and this is deliberate.
3. **`xxh128` invalidates every client's cached tag, once.** The first request after deploy is a full `200` even for unchanged content, for every client. → `hash => 'md5'` preserves existing tags byte-for-byte and avoids the storm entirely.
4. **`If-Match` uses strong comparison.** Their `if_match_weak` defaults to `true`, which strips `W/` from the client's tags. A client sending `W/"abc"` on a `PATCH` succeeded before and gets `412` now. → There is no configuration for this; §13.1.1 requires it. Fix the client — a weak validator genuinely cannot support an update guard.
5. **Every unsafe method is guarded, not just `PATCH`.** A stale `If-Match` on a `POST`, `PUT` or `DELETE` used to be ignored and is now `412`. → This is defect #1, and MDN's canonical mid-air-collision example is a wiki save over `POST`. Audit which clients send `If-Match` on non-`PATCH` writes before you deploy.
6. **A missing precondition can now be refused.** Under `conditional:required`, an unsafe request with no `If-Match`, no `If-Unmodified-Since` and no `If-None-Match` gets `428` — where the incumbent returned early and let it through. **This will reject clients that previously succeeded by omitting the header.** → Deploy in two steps: plain `conditional` first, watch your logs for which clients actually send a precondition, then add `required` per route once you know. `required` is per-route by design and there is no global switch, which makes the staged rollout the natural one.
7. **`If-None-Match: *` on a read starts producing `304`.** The incumbent compares `in_array($etag, $request->getETags())`, and `'*'` never equals a quoted tag, so a wildcard read returned `200` with the body. Symfony treats `*` as matching any validator, so it is a `304` here. → A client sending a wildcard on a `GET` starts getting empty responses. Note that this package refuses to answer a bare wildcard *before* the controller, so no gate is skipped — but the status code does change.
8. **An error response can no longer become a `304`.** `IfNoneMatch` calls `setNotModified()` without checking the status, so a `404` or a `500` whose body happened to hash to the client's tag became a `304`. Here, `Conditional::eligible()` requires `isSuccessful()`. → Strictly an improvement; listed because it is a behaviour change.
9. **An application-set `ETag` is now preserved and ignored, rather than overwritten and honoured.** `SetEtag` overwrites unconditionally. This package leaves your tag alone — and that response then takes no part in `304` handling at all. → If any route sets its own tag, its behaviour inverts. See [H11](hazards.md#h11).
10. **The internal `GET` re-dispatch is gone.** Anything that depended on it stops: a controller that logged reads is no longer invoked twice, a rate limiter no longer counts a synthetic `GET`, and the `X-From-Middleware` header is never sent. Your guarded write route also no longer needs a `GET` counterpart to exist at all. → Remove the sentinel handling from your own middleware, and check your metrics before you conclude read traffic has dropped.
11. **The write path needs the `model` strategy, and therefore a contract on your models.** `required` and `lock` imply `model`, because the current validator must be known before the controller runs and a body hash cannot supply one. The incumbent needed no model changes at all. → This is the largest single item on this list. Budget for it.
12. **Model-derived routes start emitting `Last-Modified`.** Clients that send `If-Modified-Since` behave differently from before, when the header never appeared. The date is withheld until the second holding the change has elapsed — see [`reads.md`](reads.md#last-modified-and-if-modified-since) for why. → Set `last_modified => false` to keep the family out of the conversation.

## Which defects the migration fixes, and which need work from you

Design §11.2 lists thirteen defects in the incumbent. The migration does not fix all of them for free, and "fixed for free" is the claim a reader will act on without checking, so here it is in two tables.

**Fixed by swapping `etag` for `conditional`, no other change:**

| # | Defect | You get |
| --- | --- | --- |
| 3 | `in_array('"*"', $ifMatchArray)` expects a *quoted* wildcard, so a spec-compliant `If-Match: *` gets a spurious `412` | a bare `*` parsed per RFC 9110 §13.1.1 |
| 7 | An unreachable `if ($ifMatch === null) return 412` — the method already returned early unless `hasHeader('If-Match')` | n/a: dead code, nothing to migrate |
| 10 | `setEtag` buffers and hashes streamed, binary and large responses | skip rules and a size ceiling |
| 11 | `md5()` on every response body | `xxh128`, or keep `md5` — see day-one item 3 |
| 12 | Three middleware to wire in the correct combination | one middleware, flags |
| 13 | No PHP constraint; Laravel capped at `~12` | PHP `^8.3`, Laravel `^12.0\|\|^13.0` |

**Needs work from you:**

| # | Defect | What it takes |
| --- | --- | --- |
| 1 | `IfMatch` guards `PATCH` only | the contract and the trait on each guarded model, plus a `version` column or timestamps. Then it covers every unsafe method automatically. |
| 2 | The guard is opt-out — omit `If-Match`, clobber freely | adding `required` to each guarded route, and the staged rollout in day-one item 6 |
| 4 | `if_match_weak` defaults to `true` | nothing to configure, but you must fix clients that send `W/` tags on writes |
| 5 | An internal `GET` re-dispatch per guarded write | comes free with the model strategy — so it costs the same model work as #1 |
| 6 | No lock between the check and the write | the `lock` flag, a database with real row locks ([H10](hazards.md#h10)), and lean controllers ([H9](hazards.md#h9)) |
| 8 | No `Last-Modified` / `If-Modified-Since` / `If-Unmodified-Since` | the model strategy plus usable timestamps; `body` never publishes a date |
| 9 | No `If-None-Match: *` create guard | an explicit `Route::bind()` returning `null`, because implicit binding raises `404` before the middleware runs — see [Guarding a create](writes.md#guarding-a-create--if-none-match-) |

> Every entry in the second table needs the same thing first: model-derived validators. That is the migration. The middleware rename is not.

## Rolling back

Cheap, and worth knowing before you start.

Both packages can be installed at once. They register different middleware aliases (`etag` / `setEtag` / `ifNoneMatch` / `ifMatch` against `conditional`), different config namespaces (`etagconditionals` against `laravel-conditional-requests`), and neither one touches the other's. So a rollback is:

1. Revert the route middleware to `etag` (or the individual aliases).
2. Leave the contract and the trait on your models. They are inert without the middleware — nothing calls `conditionalValidator()` unless a `model`-strategy route asks for it.
3. Re-set `hash` if you changed it.

Clients holding this package's tags see one cache miss on the way back if you changed the algorithm, and none if you did not.
