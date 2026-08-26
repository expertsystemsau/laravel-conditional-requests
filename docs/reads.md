# Conditional reads

## The short version

On a safe request the `conditional` middleware attaches a validator — an `ETag`, and on a model-derived route a `Last-Modified` — and answers `304 Not Modified` when the client already holds that version. `body` is the default and needs no setup: it hashes the rendered response, so it saves bandwidth and not compute. `model` takes the validator from the route-bound record instead, which is known **before** the controller runs, so a matching request never executes the route action. The comparison itself is Symfony's `Response::isNotModified()` in both cases, so the RFC 9110 semantics are not this package's invention and cannot drift from the framework's.

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

> [!WARNING]
> Neither key is validated at boot, and a typo in either is a `500` on every conditional route rather than a quiet loss of caching — flag matching is case-sensitive too, so `conditional:MODEL` is an unregistered strategy name. See [H13](hazards.md#h13).

> [!WARNING]
> `required` and `lock` are reserved words rather than strategy names, and both select `model`. `required` guards the [write path](writes.md#requiring-a-precondition--428); `lock` [closes the check-then-write race](writes.md#closing-the-race--lock). Both are unsafe-method flags, so putting either on a `GET` route guards nothing and only switches it from `body` to `model` — turning on the pre-controller short-circuit and [the authorization hazard that comes with it](hazards.md#h1), and on a route whose bound model does not implement `ProvidesConditionalValidator`, leaving it with no `ETag` at all.

### `body` — a hash of the rendered response

The default. The controller runs, the response is rendered, and the tag is a hash of the bytes — so the client is spared the payload and the server is spared nothing. It works on any route with no model changes, which is why it is the default.

> [!WARNING]
> Under `body` — and under any strategy that did not answer from the request — a `HEAD` request reaches your controller with its method rewritten to `GET`, so `$request->method()` inside the action returns `GET`. See [H18](hazards.md#h18).

> [!WARNING]
> Anything that changes the response body outside `conditional` leaves the tag describing bytes the client never received. See [H7](hazards.md#h7) and [`placement.md`](placement.md#what-must-run-inside-conditional).

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
  ETag: "9b1c0e0f6b0a4f9d3e7a2c81f4d6b059"

GET /articles/42
If-None-Match: "9b1c0e0f6b0a4f9d3e7a2c81f4d6b059"
→ 304 Not Modified          # the controller never ran
```

The default tag fingerprints where the record lives — the connection's database name, the connection's table prefix, and the table — together with its key and its version: an explicit `version` column when the model has one, otherwise the raw `updated_at` value. Location is in there so that two records with the same id, in different tables or in different tenants' databases, can never share a tag. A record with no version at all — one that has never been saved — produces no validator, and the response is left untouched.

Model-derived validators are **strong**. RFC 9110 §13.1.1 requires strong comparison for `If-Match`, so a weak tag could never satisfy the write-path guard.

> [!WARNING]
> A client that holds a valid tag keeps receiving `304` after its access to that record is revoked. This is inherent to answering before the controller runs — see [H1](hazards.md#h1) for the full rule and what to do about it.

> [!WARNING]
> A `version` column that does not change on every write freezes the tag, so the route keeps answering `304` against content that changed. Nullable columns and soft deletes are the sharp cases — see [H3](hazards.md#h3).

> [!WARNING]
> On a route binding more than one record that implements the contract, the **first** parameter wins and the tag tracks the wrong record. See [H4](hazards.md#h4). On a **write** route the same ambiguity is a configuration error and throws — see [the caveats for guarded routes](writes.md#requirements-and-caveats-for-guarded-routes).

> [!WARNING]
> A model that casts its primary key to an enum or a value object produces no validator at all, silently. See [H16](hazards.md#h16).

> [!WARNING]
> Two writes inside the same second produce the same tag, because `updated_at` is stored to the second by default. See [H17](hazards.md#h17).

> [!WARNING]
> A middleware declared *after* `conditional` that sets an `ETag` of its own — `cache.headers:...;etag` is the common one — turns `conditional:model` back into `body` semantics, silently, with the controller running on every request. See [H11](hazards.md#h11).

`conditional` must run **after** route model binding, which it already does inside the `api` and `web` groups. On a route where it does not, the strategy finds no record before the controller runs and the request quietly takes the ordinary path: the `ETag` is still attached on the way out, but the controller runs and nothing is saved. [`placement.md`](placement.md#what-must-run-before-conditional) is that subject's own page.

> [!NOTE]
> A short-circuited `304` cannot carry headers your controller or downstream middleware would have set — an application `Cache-Control`, `Vary`, `Content-Location`, and the like never run on a hit, because nothing that would set them does. RFC 9110 §15.4.5 says a `304` *should* carry them. The long way round — controller runs, `304` decided afterwards — carries them exactly as before; only the pre-controller short-circuit skips them. For `Cache-Control` the absence is worse than an absence, because a cache adopts what the `304` does carry: that is [H6](hazards.md#h6).

## What a model-derived tag is scoped to

> [!WARNING]
> A model-derived tag is scoped to the record and to nothing else, so one client's tag can be honoured for another client who should be seeing different bytes. Read [H5](hazards.md#h5) before using `model` on any route whose output varies by viewer, tenant, or `Accept` header.

The default tag is scoped to **the record** — where it lives, its key, its version — and to nothing else. It is not scoped to the representation, and it is not scoped to the viewer. Under `body` both came for free: the tag was a hash of the bytes actually sent, so two callers who saw different bytes got different tags without anyone having to think about it. Under `model` they do not. Anything that changes what a caller should see *without* changing the record's version has to be folded into the tag by hand.

Three cases cover almost all of it.

### The viewer

On a route where an admin sees extra fields, or one viewer sees a redacted copy, every viewer collapses to a single tag under `model`. A tag minted for the admin's response is then honoured for anyone else who presents it.

### The tenant

Under any tenancy where one URL can resolve to different records, the tag has to say which. Database-per-tenant and prefix-per-tenant are already separated, because the connection's database name and table prefix are both in the default fingerprint. Single-database row-level tenancy is not: every tenant shares one database, one prefix, and one table, so tenant A's record 1 at version 1 and tenant B's produce the same tag. On that model, fold the tenant in yourself.

### The representation

Content negotiation, sparse fieldsets, `?include=` — the same record in more than one shape. A strong validator asserts one specific representation, so each shape needs its own tag.

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

## Last-Modified and If-Modified-Since

A model-derived validator publishes the record's modification date alongside its tag, so a client can revalidate with either.

```http
GET /articles/42
→ 200 OK
  ETag: "9b1c0e0f6b0a4f9d3e7a2c81f4d6b059"
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

The date is also what the write path's date guard compares against: see [`writes.md`](writes.md#if-unmodified-since) for `If-Unmodified-Since`, which consumes exactly this value. The one-second limit on both validators is [H17](hazards.md#h17).

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

Register it from a service provider's `boot()`. Laravel resolves `boot()` parameters from the container, so type-hint the registry rather than reaching for a facade — this package registers none:

```php
use App\Http\Validators\RevisionStrategy;
use ExpertSystems\ConditionalRequests\ConditionalRequests;
use ExpertSystems\ConditionalRequests\Contracts\ValidatorStrategy;

public function boot(ConditionalRequests $conditional): void
{
    $conditional->extend('revision', fn (): ValidatorStrategy => new RevisionStrategy);
}
```

```php
Route::get('/articles/{article}', ShowArticle::class)
    ->middleware('conditional:revision');
```

> [!IMPORTANT]
> `Validator` throws an `InvalidArgumentException` for a tag that cannot appear inside a quoted entity tag: an empty one, one containing a double quote or a control character, and one containing a **comma**. A comma is legal `etagc`, but `If-Match` and `If-None-Match` carry a comma-separated list, so a tag holding one splits into two malformed members the moment a client echoes it back — a permanent `412` on that resource. The package's own strategies emit hex and cannot reach it. A custom strategy handing a raw column straight to `Validator` can, which is why the example above hashes it. Hash the value, or use one that plainly cannot contain those characters.

> [!WARNING]
> Call `extend()` from a service provider's `boot()` only. Calling it from a controller, a route closure, or any other request handler permanently mutates shared state — see [H15](hazards.md#h15).

### Answering before the controller runs

A custom strategy can take part in the short-circuit too. Implement `RequestValidatorStrategy`, which extends `ValidatorStrategy` with two methods:

```php
public function fromRequest(Request $request): ?Validator;

public function targetExists(Request $request): ?bool;
```

Answer from the request alone and the middleware will ask before the controller runs; return `null` and it falls back to `fromResponse()` afterwards. A strategy that implements only `ValidatorStrategy` keeps working exactly as it did — it is simply never asked early.

`targetExists()` is asked only by the write path, and only so the `If-None-Match: *` create guard can tell "absent" from "present but silent". Return `true` when the addressed resource is there, `false` when it definitely is not, and `null` when you cannot tell. A `null` fails the guard closed with `412`; answering `false` when you do not know reopens the hole the method exists to close — a live record silently overwritten by the one precondition meant to protect it.

Answering early also suppresses the streamed, binary, and size-ceiling checks for that response, on the grounds that a validator already in hand cost no body read. **The suppression follows the answer rather than the interface.** On the `null` path `fromResponse()` is asked the ordinary way and faces every one of those rules, so a streamed, binary, or oversized response simply goes untagged there — and the strategy is treated exactly as a body-derived one throughout, `HEAD` handling included.

> [!WARNING]
> Every `RequestValidatorStrategy` carries the authorization caveat, not just `model`. Whenever `fromRequest()` answers and the client's tag matches, the `304` goes out before anything declared after `conditional` runs. See [H1](hazards.md#h1).

## Responses that never get a validator

Six rules, all but one of them in `Conditional::eligible()` and `Conditional::requestEligible()` — the empty-body rule lives in `BodyHashStrategy::fromResponse()`:

- **Anything that is not 2xx.** `$response->isSuccessful()` gates the lot. A validator on an error response is meaningless.
- **Anything that already carries an `ETag`.** Your tag is preserved — and that response then takes no part in `304` handling at all. Those are the same sentence and only the first half is obvious: see [H11](hazards.md#h11).
- **A `StreamedResponse` or a `BinaryFileResponse`.** Hashing one means buffering it. **Only when the validator would have to come from the body**: if the strategy already answered from the request, these responses *are* tagged, because that validator demonstrably cost no body read.
- **A body larger than `max_response_bytes`** — 1 MiB by default, and `0` or any non-positive value means unlimited. Same suppression rule as above.
- **An empty body.** `BodyHashStrategy` returns `null` for `''`, so a `204` gets nothing.
- **A request whose method is not in `methods`**, or whose route name or URI matches `exclude`.

The last one is two different keys and they behave differently. `methods` — `GET` and `HEAD` by default — is a **read-path gate only**: listing an unsafe method there does not start attaching validators to its responses, and removing one does not disable the write path, which applies to every unsafe method on a route carrying the middleware. `exclude` suppresses **both** paths and is the key to reach for when a route must opt out entirely.
