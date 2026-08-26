# Conditional reads

## The short version

<!-- Task 4 -->

## Choosing a validator strategy

A *strategy* is what produces the validator. Two ship:

| Flag | Source | Runs | Cost saved |
| --- | --- | --- | --- |
| `body` | a hash of the rendered response | after the controller | bandwidth |
| `model` | the route-bound record's own version | **before** the controller | bandwidth and compute |

`body` is the default and needs no setup. Name either one as a middleware flag on a single route:

```php
Route::get('/articles/{article}', ShowArticle::class)
    ->middleware('conditional:body');
```

Or change the default for every route in `config/laravel-conditional-requests.php`:

```php
'strategy' => 'body',
```

A flag always wins over the config key.

> [!NOTE]
> Neither key is validated at boot. A `strategy` that names nothing registered, or a `hash` that `hash()` does not know, is a `500` on every request that reaches an eligible route — `Conditional request strategy [nope] is not registered. Registered: body, model`, and `Hash algorithm [nope] is not supported. Check the laravel-conditional-requests.hash config value.` Only the hash message names the key at fault; the strategy message cannot, because the name it rejects may have arrived from a route flag rather than from the config, so it lists what is registered instead. Routes that name a valid strategy by flag keep working, but a typo in a published config file arrives in production as an outage across every conditional route rather than as a quiet loss of caching. Flag matching is case-sensitive too: `conditional:MODEL` is not `conditional:model`, and asks the registry for a strategy of that name.

> [!WARNING]
> `required` and `lock` are reserved words rather than strategy names, and both select `model`. `required` guards the [write path](#conditional-writes-lost-update-protection); `lock` parses and does nothing yet. Both are unsafe-method flags, so putting either on a `GET` route guards nothing and only switches it from `body` to `model`, turning on the pre-controller short-circuit and [the authorization hazard that comes with it](#model-derived-validators) — and on a route whose bound model does not implement `ProvidesConditionalValidator`, leaving it with no `ETag` at all.

### `body` — a hash of the rendered response

> [!NOTE]
> If a response already carries an `ETag` — set by your own application code — the middleware leaves it completely alone: your tag is preserved, and that response never takes part in `304` handling.

> [!NOTE]
> Under a strategy that derives its validator from the rendered body — `body`, and any custom strategy implementing only `ValidatorStrategy` — a `HEAD` request is handed to your controller as a `GET` while the validator is computed, so it gets the same `ETag` and can still trigger a `304`; the body is emptied again before the response goes out. In practice this is invisible, since Laravel already routes `HEAD` to the `GET` action — but controller code that inspects `$request->method()` or `isMethod('HEAD')` will see `GET` for the duration of that call. Under a request-derived strategy such as `model` there is no body to hash, so nothing is mutated and the controller sees the `HEAD` the client sent. The mutation also waits for routing: under kernel-global placement `conditional` runs ahead of the router, where rewriting the method would change which route a `HEAD` matches, so the method is left alone and the response goes untagged instead.

### `model` — the record's own version

The `model` strategy takes the validator from the route-bound record rather than from the rendered body. Because the record's version is known **before** the controller runs, a request whose `If-None-Match` already matches is answered with `304` without executing the route action — no serialization. It does not cost no queries: with implicit route-model binding, the only wiring documented here, `SubstituteBindings` issues the binding query before `conditional` runs, so a short-circuited `304` still costs that one query.

Add the contract and the trait to the model:

```php
namespace App\Models;

use ExpertSystems\ConditionalRequests\Concerns\HasConditionalValidator;
use ExpertSystems\ConditionalRequests\Contracts\ProvidesConditionalValidator;
use Illuminate\Database\Eloquent\Model;

class Article extends Model implements ProvidesConditionalValidator
{
    use HasConditionalValidator;
}
```

Then name the strategy on the route:

```php
Route::get('/articles/{article}', ShowArticle::class)
    ->middleware('conditional:model');
```

```http
GET /articles/42
→ 200 OK
  ETag: "9b1c0e0f6b0a4f9d"

GET /articles/42
If-None-Match: "9b1c0e0f6b0a4f9d"
→ 304 Not Modified          # the controller never ran
```

> [!WARNING]
> A matching tag skips **everything declared after `conditional`** — the controller, and every middleware between the two. A client holding a still-valid tag keeps receiving `304` after its access to that record is revoked, or for a record the controller would otherwise have hidden from it. Authorization is the obvious case, but the rule is broader than `can:`: a `signed` check, a subscription gate, a feature flag, a rate limiter — anything after `conditional` that can reject the request never runs on a hit. This is inherent to answering before the controller, not a defect. Mitigate it by placing `conditional` **after** every middleware that can reject the request, so one that should be rejected never reaches the strategy at all, and by knowing that a per-record check made *inside* the controller action itself is skipped entirely on a hit — it has to live in middleware to apply.

> [!NOTE]
> Two entry points into that hazard are closed here rather than left to you. `If-None-Match: *` matches *any* validator, so it needs no tag and no prior access — and behind a gate declared after `conditional` the status code alone would then separate the records that exist from the ones that do not, for every id, with the gate never entered. A bare wildcard therefore never short-circuits: the controller and everything after `conditional` run, and the `304` is decided at the end exactly as it is under `body`. A wildcard sent *alongside* a tag that does match is a client demonstrably holding the current version, and still short-circuits. A lone `If-Modified-Since` is the [second such entry point](#last-modified-and-if-modified-since) and is closed the same way. This closes two entry points, not the hazard — the rule above stands unchanged.

> [!WARNING]
> A middleware the short-circuit skipped still has its `terminate()` called. `Kernel::terminateMiddleware()` calls `terminate()` on everything the route gathered, whether or not that middleware's `handle()` ever ran — so a terminable middleware declared after `conditional` runs its teardown against state its setup never created. A request timer subtracts from a `null` start and reports the epoch; a metric emitter closes a span it never opened; a log context is popped that was never pushed; a buffer is flushed that was never filled. Under Octane it is worse than `null`: the worker still holds whatever the *previous* request left in that property. The asymmetry is the trap — the middleware after `conditional` do not run on a hit, but their `terminate()` does. Nothing here can change that, because as far as the kernel is concerned the middleware was on the route. Declare terminable middleware **outside** `conditional`, where both halves run as usual.

The default tag fingerprints where the record lives — the connection's database name, the connection's table prefix, and the table — together with its key and its version: an explicit `version` column when the model has one, otherwise the raw `updated_at` value. Location is in there so that two records with the same id, in different tables or in different tenants' databases, can never share a tag. A record with no version at all — one that has never been saved — produces no validator, and the response is left untouched.

> [!WARNING]
> A `version` column has to change on **every** write. The trait prefers it over `updated_at` unconditionally, so a column that already exists and means something else — a document revision label, a schema version, a counter only some code paths bump — freezes the tag and keeps answering `304` against content that has changed. A nullable one is the sharpest case: rows where it is `null` are tracked by `updated_at` and rows where it holds a value are tracked by the column, so populating it once silently stops that record tracking edits at all. Soft deletes are the same root cause — `runSoftDelete()` bumps `deleted_at` and `updated_at`, never `version`. If your `version` column does not mean *this row changed*, point `conditionalVersionColumns()` at something that does.

Model-derived validators are **strong**. RFC 9110 §13.1.1 requires strong comparison for `If-Match`, so a weak tag could never satisfy the write-path guard.
> [!NOTE]
> `updated_at` is stored to the second by default, so two writes inside the same second produce the same tag. Add a `version` column, or widen the column's precision, on resources that change that fast. The `Last-Modified` header has the same one-second limit and cannot be widened at all — see [Last-Modified and If-Modified-Since](#last-modified-and-if-modified-since) for what the package does about it.

> [!NOTE]
> A short-circuited `304` cannot carry headers your controller or downstream middleware would have set — an application `Cache-Control`, `Vary`, `Content-Location`, and the like never run on a hit, because nothing that would set them does. RFC 9110 §15.4.5 says a `304` *should* carry them. The long way round — controller runs, `304` decided afterwards — carries them exactly as before; only the pre-controller short-circuit skips them.
>
> For `Cache-Control` the header is not merely absent, which matters more than it sounds. The short-circuited response is a fresh Laravel `Response`, so it leaves carrying the framework default, `no-cache, private`, and RFC 9111 §4.3.4 has a cache replace the header fields it stored with the ones on the `304`. A `public, max-age=60` entry is therefore downgraded to `private` — out of every shared cache — and marked `no-cache`, so it revalidates on every request from then on. The policy destroys itself the first time it succeeds. A middleware declared *before* `conditional` can still put the header back on the way out, but only if it sets headers unconditionally: Laravel's own `cache.headers` returns early for an empty response and again for one that is not `2xx`, so it will not.

> [!WARNING]
> A middleware declared *after* `conditional` that sets an `ETag` of its own turns `conditional:model` back into `body` semantics, silently. `cache.headers:public;max_age=60;etag` is the common one. It tags the response first, `conditional` honours the rule that an existing tag is left alone, and the route ends up advertising a body hash that the model short-circuit can never match — so the controller runs on every request, including the ones that answer `304`. You asked for the compute-saving strategy and got the bandwidth-only one, with nothing to say so. Stacking `conditional:model` outside `conditional:body` does the same thing, for the same reason. Pick one tag source per route.

> [!WARNING]
> When a route binds more than one conditional record, the **first** route parameter implementing the contract wins — parameters keep their declaration order, so on `/articles/{article}/comments/{comment}` the tag tracks the *article*, and editing the comment never moves it. On `/{tenant}/articles/{article}` it is worse: every article under that tenant shares one tag, the tenant's. First-wins is deliberate — deterministic beats clever, and the target never moves with runtime state — but it means you should implement `ProvidesConditionalValidator` only on the record the route actually represents, or override `conditionalValidator()` on the outer one to fold the inner record's version in. On a **write** route the same ambiguity is a configuration error and throws a `LogicException` naming the route and the candidates: see the caveat under [conditional writes](#requirements-and-caveats-for-guarded-routes).

## What a model-derived tag is scoped to

The default tag is scoped to **the record** — where it lives, its key, its version — and to nothing else. It is not scoped to the representation, and it is not scoped to the viewer. Under `body` both came for free: the tag was a hash of the bytes actually sent, so two callers who saw different bytes got different tags without anyone having to think about it. Under `model` they do not. Anything that changes what a caller should see *without* changing the record's version has to be folded into the tag by hand.

Three cases cover almost all of it:

- **The authenticated user.** On a route where an admin sees extra fields, or one viewer sees a redacted copy, every viewer collapses to a single tag under `model`. A tag minted for the admin's response is then honoured for anyone else who presents it.
- **The tenant.** Under any tenancy where one URL can resolve to different records, the tag has to say which. Database-per-tenant and prefix-per-tenant are already separated, because the connection's database name and table prefix are both in the default fingerprint. Single-database row-level tenancy is not: every tenant shares one database, one prefix, and one table, so tenant A's record 1 at version 1 and tenant B's produce the same tag. On that model, fold the tenant in yourself.
- **The representation.** Content negotiation, sparse fieldsets, `?include=` — the same record in more than one shape. A strong validator asserts one specific representation, so each shape needs its own tag.

That is what the `$request` argument is for:

```php
use ExpertSystems\ConditionalRequests\Concerns\HasConditionalValidator;
use ExpertSystems\ConditionalRequests\Contracts\ProvidesConditionalValidator;
use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class Article extends Model implements ProvidesConditionalValidator
{
    use HasConditionalValidator {
        conditionalValidator as baseConditionalValidator;
    }

    public function conditionalValidator(Request $request): ?Validator
    {
        $validator = $this->baseConditionalValidator($request);

        if (! $validator instanceof Validator) {
            return null;
        }

        return new Validator(
            hash('xxh128', $validator->etag."\0".(string) $request->query('fields')),
            lastModified: $validator->lastModified,
        );
    }
}
```

Pass `lastModified` through when you rebuild a validator. It is a separate field from the tag, and a rebuild that forgets it quietly drops the `Last-Modified` header for that model.

The example varies on `?fields=`; the viewer and the tenant fold in exactly the same way — `$request->user()?->getAuthIdentifier()`, a tenant id, an `Accept` header. Anything that is part of *which* representation this is belongs in there.

### The viewer

<!-- Task 4 -->

### The tenant

<!-- Task 4 -->

### The representation

<!-- Task 4 -->

## Last-Modified and If-Modified-Since

A model-derived validator publishes the record's modification date alongside its tag, so a client can revalidate with either.

```http
GET /articles/42
→ 200 OK
  ETag: "9b1c0e0f6b0a4f9d"
  Last-Modified: Wed, 26 Aug 2026 12:00:00 GMT

GET /articles/42
If-Modified-Since: Wed, 26 Aug 2026 12:00:00 GMT
→ 304 Not Modified          # the controller ran; see the note below
```

> [!NOTE]
> **A client that sends only a date never takes the pre-controller short-circuit.** A date needs no prior access — the client can guess one — so an early `304` on it would confirm a record, and by bisecting the date reveal the second it last changed in, to a client holding nothing and cleared by nothing declared after `conditional`. This is the same rule `If-None-Match: *` gets and it is drawn for the same reason. A date-only request therefore runs the controller and everything after `conditional`, and its `304` is decided at the end: the response is identical, only the compute saving is gone. A date sent *alongside* an `If-None-Match` that matches is a client demonstrably holding the current version, and still short-circuits.

The date comes from `updated_at`. A model with no timestamps, a null `UPDATED_AT` column, or an unloaded `updated_at` publishes no date and keeps its tag; override `conditionalLastModifiedColumn()` to point at a different column, or to return `null` to suppress the date for a model entirely. The body-hash strategy never publishes one — it fingerprints content, and has no idea when that content changed.

```php
class Article extends Model implements ProvidesConditionalValidator
{
    use HasConditionalValidator;

    // Required: an uncast column publishes no date. See the note below.
    protected $casts = ['published_at' => 'datetime'];

    protected function conditionalLastModifiedColumn(): ?string
    {
        return 'published_at';
    }
}
```

> [!IMPORTANT]
> **A column you name in `conditionalLastModifiedColumn()` must be cast to a date on the model.** The value is read with `getAttribute()`, which returns a `DateTimeInterface` only for a cast column — Eloquent casts `created_at` and `updated_at` and nothing else on your behalf. Without `protected $casts = ['published_at' => 'datetime'];` the column comes back as the raw string the database holds, and the model publishes no `Last-Modified` at all. Nothing fails: the `ETag` is unaffected and keeps validating the record, so the only symptom is a date that never appears.

> [!IMPORTANT]
> **A record that has just changed publishes no `Last-Modified` until the second it changed in has elapsed.** This is deliberate, and it is the one thing most `Last-Modified` implementations get wrong.
>
> An HTTP-date has one-second resolution. A record modified at `12:00:00.700` can only be advertised as `12:00:00` — and if it changes again at `12:00:00.900`, a client echoing `If-Modified-Since: 12:00:00` back would be told `304 Not Modified` while holding a stale representation. The same is true of a plain second-precision column, where both writes store `12:00:00`; widening the column does not help, because the limit is in the header format.
>
> RFC 9110 §8.8.2.2 allows a date to be treated as a strong validator only when the server knows the representation did not change twice inside the second it names. That is unknowable while the second is still running and always true once it has finished, so the date is published a moment later. In the meantime the `ETag` — which is derived from the raw column at full precision — keeps validating the resource, which is why the two headers ship together.

> [!NOTE]
> A `304` carries the `ETag` and no `Last-Modified`: RFC 9110 §15.4.5 requires the tag and wants the date only when there is no tag, and your client keeps the date it stored from the original `200` (RFC 9111 §4.3.4).

> [!NOTE]
> Set `last_modified => false` in the config to keep the whole family out of the conversation. Responses then carry no date, `If-Modified-Since` can never produce a `304`, and `If-Unmodified-Since` is refused rather than ignored. Attaching a date never changes what a response says about caching — the middleware restores the `Cache-Control` the response already had, so a validator can never be the reason a resource stops being revalidated.

## Registering your own strategy

Implement `ValidatorStrategy` and register it under a name, which then works as a middleware flag exactly like a built-in one:

```php
namespace App\Http\Validators;

use ExpertSystems\ConditionalRequests\Contracts\ValidatorStrategy;
use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RevisionStrategy implements ValidatorStrategy
{
    public function fromResponse(Request $request, Response $response): ?Validator
    {
        $article = $request->route('article');

        if (! $article) {
            // Returning null leaves the response untouched.
            return null;
        }

        return new Validator(hash('xxh128', (string) $article->revision));
    }
}
```

```php
use App\Http\Validators\RevisionStrategy;
use ExpertSystems\ConditionalRequests\Facades\ConditionalRequests;

public function boot(): void
{
    ConditionalRequests::extend('revision', fn () => new RevisionStrategy);
}
```

```php
Route::get('/articles/{article}', ShowArticle::class)
    ->middleware('conditional:revision');
```

> [!IMPORTANT]
> `Validator` throws an `InvalidArgumentException` for a tag that cannot appear inside a quoted entity tag: an empty one, one containing a double quote or a control character, and one containing a **comma**. A comma is legal `etagc`, but `If-Match` and `If-None-Match` carry a comma-separated list, so a tag holding one splits into two malformed members the moment a client echoes it back — a permanent `412` on that resource. The package's own strategies emit hex and cannot reach it. A custom strategy handing a raw column straight to `Validator` can, which is why the example above hashes it. Hash the value, or use one that plainly cannot contain those characters.

> [!IMPORTANT]
> Call `extend()` from a service provider's `boot()` method only. The registry is a container singleton, so calling it from a controller, a route closure, or any other request handler permanently mutates shared state — under Laravel Octane that means for the whole worker, for every subsequent request it serves.

A custom strategy can take part in the short-circuit too. Implement `RequestValidatorStrategy`, which extends `ValidatorStrategy` with one method:

```php
public function fromRequest(Request $request): ?Validator;
```

Answer from the request alone and the middleware will ask before the controller runs; return `null` and it falls back to `fromResponse()` afterwards. A strategy that implements only `ValidatorStrategy` keeps working exactly as it did — it is simply never asked early.

Answering early also suppresses the streamed, binary, and size-ceiling checks for that response, on the grounds that a validator already in hand cost no body read. The suppression follows the answer rather than the interface: on the `null` path `fromResponse()` is asked the ordinary way and faces every one of those rules, so a streamed, binary, or oversized response simply goes untagged there. On the `null` path the strategy is treated exactly as a body-derived one throughout, `HEAD` handling included.

> [!WARNING]
> Every `RequestValidatorStrategy` carries the authorization caveat, not just `model`. Whenever `fromRequest()` answers and the client's tag matches, the `304` goes out before anything declared after `conditional` runs — `can:`, `signed`, subscription and feature gates, and any check inside the controller action. Place `conditional` after every middleware that can reject the request, and see the [`model` warning above](#model-derived-validators) for what that costs if you do not.

### Answering before the controller runs

<!-- Task 4 -->

## Responses that never get a validator

<!-- Task 4 -->
