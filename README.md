<div align="center">
    <h1>Laravel Conditional Requests</h1>
    <p><strong>HTTP conditional requests for Laravel — ETag, Last-Modified, 304 Not Modified, and lost-update protection with <code>If-Match</code>.</strong></p>
</div>

<p align="center">
    <a href="https://packagist.org/packages/expertsystemsau/laravel-conditional-requests"><img src="https://img.shields.io/packagist/v/expertsystemsau/laravel-conditional-requests.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/expertsystemsau/laravel-conditional-requests"><img src="https://img.shields.io/packagist/php-v/expertsystemsau/laravel-conditional-requests.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/expertsystemsau/laravel-conditional-requests"><img src="https://badge.laravel.cloud/badge/expertsystemsau/laravel-conditional-requests?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/expertsystemsau/laravel-conditional-requests/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/expertsystemsau/laravel-conditional-requests/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/expertsystemsau/laravel-conditional-requests"><img src="https://img.shields.io/packagist/dt/expertsystemsau/laravel-conditional-requests.svg?style=flat-square" alt="Total Downloads"></a>
    <a href="LICENSE.md"><img src="https://img.shields.io/packagist/l/expertsystemsau/laravel-conditional-requests.svg?style=flat-square" alt="License"></a>
</p>

Conditional requests are the part of HTTP that lets a client and a server agree on *which version* of a resource they are talking about, before either of them acts on it. [RFC 9110 §13](https://www.rfc-editor.org/rfc/rfc9110#section-13) defines them; ETag and `Last-Modified` are just the two validators underneath.

They solve two different problems with the same handshake:

- **Reads** — the client already holds version `v7`. If nothing has changed, it should get an empty `304 Not Modified` instead of the payload again. Less bandwidth, less serialization, faster clients.
- **Writes** — the client read version `v7`, edited it, and is now sending it back. If someone else saved `v8` in between, the write must be **refused**, not silently applied over the top. This is the lost update problem, and `If-Match` is the fix.

Most Laravel packages in this space only do the first half, and only via ETag. This one treats conditional requests as the feature and validators as an implementation detail.

## Status

> [!WARNING]
> **Pre-release — under active development.** The read path and the write path described below both ship and are tested, model-derived validators and the pre-controller `304` short-circuit included. The `Last-Modified` family (`If-Modified-Since`, `If-Unmodified-Since`) and `lock` mode are not implemented yet — they are marked on the [roadmap](#roadmap) below. Nothing is stable until `v1.0.0`.

## Requirements

- PHP 8.3, 8.4, or 8.5
- Laravel 12.x or 13.x

## Installation

Install the package via Composer:

```bash
composer require expertsystemsau/laravel-conditional-requests
```

The service provider is auto-discovered. No further setup is required to get started.

### Publishing Resources

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="laravel-conditional-requests"
```

Or publish each resource individually:

```bash
php artisan vendor:publish --tag="laravel-conditional-requests-config"
php artisan vendor:publish --tag="laravel-conditional-requests-lang"
php artisan vendor:publish --tag="laravel-conditional-requests-assets"
```

## Usage

### Conditional reads

Apply the middleware to any route that returns a cacheable representation. The response gets a validator attached, and matching subsequent requests short-circuit to `304 Not Modified` with an empty body.

```php
Route::get('/articles/{article}', ShowArticle::class)
    ->middleware('conditional');
```

```http
GET /articles/42
→ 200 OK
  ETag: "d41d8cd98f00b204"

GET /articles/42
If-None-Match: "d41d8cd98f00b204"
→ 304 Not Modified          # no body, no serialization
```

> [!NOTE]
> If a response already carries an `ETag` — set by your own application code — the middleware leaves it completely alone: your tag is preserved, and that response never takes part in `304` handling.

> [!NOTE]
> Under a strategy that derives its validator from the rendered body — `body`, and any custom strategy implementing only `ValidatorStrategy` — a `HEAD` request is handed to your controller as a `GET` while the validator is computed, so it gets the same `ETag` and can still trigger a `304`; the body is emptied again before the response goes out. In practice this is invisible, since Laravel already routes `HEAD` to the `GET` action — but controller code that inspects `$request->method()` or `isMethod('HEAD')` will see `GET` for the duration of that call. Under a request-derived strategy such as `model` there is no body to hash, so nothing is mutated and the controller sees the `HEAD` the client sent. The mutation also waits for routing: under kernel-global placement `conditional` runs ahead of the router, where rewriting the method would change which route a `HEAD` matches, so the method is left alone and the response goes untagged instead.

### Choosing a validator strategy

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

### Model-derived validators

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
> One entry point into that hazard is closed here rather than left to you. `If-None-Match: *` matches *any* validator, so it needs no tag and no prior access — and behind a gate declared after `conditional` the status code alone would then separate the records that exist from the ones that do not, for every id, with the gate never entered. A bare wildcard therefore never short-circuits: the controller and everything after `conditional` run, and the `304` is decided at the end exactly as it is under `body`. A wildcard sent *alongside* a tag that does match is a client demonstrably holding the current version, and still short-circuits. This closes one entry point, not the hazard — the rule above stands unchanged.

> [!WARNING]
> A middleware the short-circuit skipped still has its `terminate()` called. `Kernel::terminateMiddleware()` calls `terminate()` on everything the route gathered, whether or not that middleware's `handle()` ever ran — so a terminable middleware declared after `conditional` runs its teardown against state its setup never created. A request timer subtracts from a `null` start and reports the epoch; a metric emitter closes a span it never opened; a log context is popped that was never pushed; a buffer is flushed that was never filled. Under Octane it is worse than `null`: the worker still holds whatever the *previous* request left in that property. The asymmetry is the trap — the middleware after `conditional` do not run on a hit, but their `terminate()` does. Nothing here can change that, because as far as the kernel is concerned the middleware was on the route. Declare terminable middleware **outside** `conditional`, where both halves run as usual.

The default tag fingerprints where the record lives — the connection's database name, the connection's table prefix, and the table — together with its key and its version: an explicit `version` column when the model has one, otherwise the raw `updated_at` value. Location is in there so that two records with the same id, in different tables or in different tenants' databases, can never share a tag. A record with no version at all — one that has never been saved — produces no validator, and the response is left untouched.

> [!WARNING]
> A `version` column has to change on **every** write. The trait prefers it over `updated_at` unconditionally, so a column that already exists and means something else — a document revision label, a schema version, a counter only some code paths bump — freezes the tag and keeps answering `304` against content that has changed. A nullable one is the sharpest case: rows where it is `null` are tracked by `updated_at` and rows where it holds a value are tracked by the column, so populating it once silently stops that record tracking edits at all. Soft deletes are the same root cause — `runSoftDelete()` bumps `deleted_at` and `updated_at`, never `version`. If your `version` column does not mean *this row changed*, point `conditionalVersionColumns()` at something that does.

Model-derived validators are **strong**. RFC 9110 §13.1.1 requires strong comparison for `If-Match`, so a weak tag could never satisfy the write-path guard.

> [!IMPORTANT]
> `conditional` must run **after** route model binding. Inside the `api` or `web` middleware group that is already true, since `SubstituteBindings` belongs to both. On a route that has not had its bindings substituted yet — kernel-global placement, or a hand-written middleware list that puts `conditional` first — the strategy finds no record before the controller runs and the request quietly takes the ordinary path: the `ETag` is still attached on the way out, but the controller runs and nothing is saved.

> [!NOTE]
> `updated_at` is stored to the second by default, so two writes inside the same second produce the same tag. Add a `version` column, or widen the column's precision, on resources that change that fast.

> [!NOTE]
> A short-circuited `304` cannot carry headers your controller or downstream middleware would have set — an application `Cache-Control`, `Vary`, `Content-Location`, and the like never run on a hit, because nothing that would set them does. RFC 9110 §15.4.5 says a `304` *should* carry them. The long way round — controller runs, `304` decided afterwards — carries them exactly as before; only the pre-controller short-circuit skips them.
>
> For `Cache-Control` the header is not merely absent, which matters more than it sounds. The short-circuited response is a fresh Laravel `Response`, so it leaves carrying the framework default, `no-cache, private`, and RFC 9111 §4.3.4 has a cache replace the header fields it stored with the ones on the `304`. A `public, max-age=60` entry is therefore downgraded to `private` — out of every shared cache — and marked `no-cache`, so it revalidates on every request from then on. The policy destroys itself the first time it succeeds. A middleware declared *before* `conditional` can still put the header back on the way out, but only if it sets headers unconditionally: Laravel's own `cache.headers` returns early for an empty response and again for one that is not `2xx`, so it will not.

> [!WARNING]
> A middleware declared *after* `conditional` that sets an `ETag` of its own turns `conditional:model` back into `body` semantics, silently. `cache.headers:public;max_age=60;etag` is the common one. It tags the response first, `conditional` honours the rule that an existing tag is left alone, and the route ends up advertising a body hash that the model short-circuit can never match — so the controller runs on every request, including the ones that answer `304`. You asked for the compute-saving strategy and got the bandwidth-only one, with nothing to say so. Stacking `conditional:model` outside `conditional:body` does the same thing, for the same reason. Pick one tag source per route.

> [!WARNING]
> When a route binds more than one conditional record, the **first** route parameter implementing the contract wins — parameters keep their declaration order, so on `/articles/{article}/comments/{comment}` the tag tracks the *article*, and editing the comment never moves it. On `/{tenant}/articles/{article}` it is worse: every article under that tenant shares one tag, the tenant's. First-wins is deliberate — deterministic beats clever, and the target never moves with runtime state — but it means you should implement `ProvidesConditionalValidator` only on the record the route actually represents, or override `conditionalValidator()` on the outer one to fold the inner record's version in.

#### What the tag is scoped to

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

        return new Validator(hash('xxh128', $validator->etag."\0".(string) $request->query('fields')));
    }
}
```

The example varies on `?fields=`; the viewer and the tenant fold in exactly the same way — `$request->user()?->getAuthIdentifier()`, a tenant id, an `Accept` header. Anything that is part of *which* representation this is belongs in there.

### Registering your own strategy

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

### Middleware that rewrites the response

A validator identifies one specific set of bytes, and this middleware computes it from the bytes it can see, where it sits. Anything that rewrites the body *after* that — an HTML minifier, a CSP-nonce injector, a CSRF token refresher, a debug bar, a response filter of any kind — leaves the tag describing bytes the client never received. Global middleware always run outside route middleware, so a global rewriter is always in that position; a route middleware declared before `conditional` is too.

```http
ETag: "d5bdba419a6ee56156a6005f54f6b73f"    nonce="ee6508f9fd94"
ETag: "d5bdba419a6ee56156a6005f54f6b73f"    nonce="d2e555ab4bb1"
ETag: "d5bdba419a6ee56156a6005f54f6b73f"    nonce="f73849aac055"
```

One strong tag, three different bodies. A client revalidating with it is told `304` every time, so it keeps the first nonce forever while the server goes on minting new ones — a CSP nonce frozen, or a stale CSRF token in every form on the page.

Declare anything that rewrites the body **after** `conditional`, so the bytes are final before they are hashed. Under `model` the ordering does not help, because that tag never described the bytes to begin with: a per-response nonce or token is exactly what [the scoping section](#what-the-tag-is-scoped-to) says has to be folded into the tag by hand, or kept off a conditional route.

### Proxies and content coding

A validator identifies one specific set of bytes, and a reverse proxy that compresses your response changes those bytes. nginx drops the `ETag` outright when it gzips; Apache appends `-gzip` to it. Either way the tag the client holds is not the tag this middleware computed, so `If-None-Match` stops matching and you never see a `304`.

If `304`s work in local development and never in production, check the proxy first — `curl -sI -H 'Accept-Encoding: gzip' <url>` against the proxy and against the app directly will show the difference immediately.

## Conditional writes (lost update protection)

Require the client to state which version it believes it is modifying. A stale token is rejected with `412`; a missing one with `428`.

```php
Route::patch('/articles/{article}', UpdateArticle::class)
    ->middleware('conditional:required');
```

```http
PATCH /articles/42
If-Match: "9b1c0e0f6b0a4f9d"
→ 200 OK                    # still current, write applied

PATCH /articles/42
If-Match: "9b1c0e0f6b0a4f9d"
→ 412 Precondition Failed   # someone else got there first

PATCH /articles/42
                            # no If-Match header at all
→ 428 Precondition Required
```

`428` is the piece most implementations skip. Without it a client can simply omit the header and go straight back to clobbering other people's writes — the protection is opt-out by default. `conditional:required` makes it opt-in-by-force for the routes you choose.

The guard applies to **every** unsafe method — `POST`, `PUT`, `PATCH`, and `DELETE`. MDN's canonical mid-air-collision example is a wiki save over `POST`, so restricting it to `PATCH` would miss the documented case.

`If-Match` uses **strong** comparison, as RFC 9110 §13.1.1 requires: a `W/`-prefixed token never satisfies it, and neither does a weak validator on the server side. This is not configurable — see the caveat below.

### Guarding a create

`If-None-Match: *` is the mirror image: it succeeds only when the resource does **not** already exist. Two clients racing to create the same resource then produce one success and one `412`, instead of a silent duplicate or one overwriting the other.

```http
PUT /articles/42
If-None-Match: *
→ 200 OK                    # created

PUT /articles/42
If-None-Match: *
→ 412 Precondition Failed   # someone else created it first
```

For the guard to be able to answer, the route has to address the resource being created and its binding has to be able to report "absent" rather than aborting. Implicit binding raises a `404` for a missing record before the middleware ever runs, so register an explicit binder that returns `null`:

```php
Route::bind('article', fn (string $value): ?Article => Article::query()->find($value));
```

On a collection route such as `POST /articles` there is no bound resource to ask about, so the create guard has nothing to compare and the request proceeds.

### Requirements and caveats for guarded routes

> [!IMPORTANT]
> `conditional:required` must run **after** `SubstituteBindings`, and its model must produce a validator. Inside the `api` or `web` middleware group the ordering is already right. Get it wrong — kernel-global placement, or a hand-written list that puts `conditional` first — and the guard finds no record, treats every resource as absent, and refuses **every** `If-Match` with `412`. On the read path a wrong ordering only costs the compute saving; on the write path it stops writes.

> [!IMPORTANT]
> Under kernel-global placement only half of `exclude` can suppress the write guard. The decision has to precede the controller, and nothing has been routed at that point, so `Request::routeIs()` answers false for every pattern: a **route-name** exclusion such as `admin.*` is silently ignored on the write path there. **URI** patterns such as `internal/*` still work, as does `enabled => false`. Under route or group placement — the ordering the section above already requires — both halves work as documented.

> [!IMPORTANT]
> A model with no `version` column and no timestamps produces no validator, and a resource with no validator reads as absent. `If-Match: *` on such a route returns `412` every time. Add a `version` column or enable timestamps — the same rule the read path already needs.

> [!WARNING]
> Setting `weak => true` and flagging a route `required` is a configuration error and throws a `LogicException` on the first guarded request, naming the config key. A weak validator can never satisfy `If-Match`, so the pair would refuse every write with `412` and nothing in the response would say why. The same applies to naming a strategy that cannot produce a validator before the controller runs, such as `conditional:body,required`.

> [!NOTE]
> `If-Match` is evaluated first, per RFC 9110 §13.2.2; `If-None-Match` is consulted only in its absence. `If-Unmodified-Since` is not implemented yet, which means it does **not** satisfy `required` — a request carrying only that header still gets `428`.

> [!NOTE]
> `If-Match` closes the window between the client's read and its write, not the window between this check and the controller's own write. Two writes that both pass the guard microseconds apart can still race. `lock` mode, which re-evaluates the precondition inside a transaction holding a row lock, is on the roadmap.

Both refusals are `Symfony\Component\HttpKernel\Exception\HttpException` subclasses, so your application's existing exception handler renders and customises them:

```php
use ExpertSystems\ConditionalRequests\Exceptions\PreconditionFailedException;

$exceptions->render(function (PreconditionFailedException $e) {
    return response()->json(['error' => 'stale'], 412);
});
```

Their default bodies live in `lang/en/messages.php`; publish it with the `laravel-conditional-requests-lang` tag to reword them.

## Header reference

| Request header | Applies to | On match | On mismatch | Ships |
| --- | --- | --- | --- | --- |
| `If-None-Match` | reads | `304 Not Modified` | `200 OK` with body | yes |
| `If-None-Match: *` | writes | `412 Precondition Failed` | write proceeds | yes |
| `If-Modified-Since` | reads | `304 Not Modified` | `200 OK` with body | no |
| `If-Match` | writes | write proceeds | `412 Precondition Failed` | yes |
| `If-Unmodified-Since` | writes | write proceeds | `412 Precondition Failed` | no |
| *(absent)* | writes, when required | — | `428 Precondition Required` | yes |

## Roadmap

- [x] `conditional` middleware — response validators and `304` short-circuiting
- [x] Configurable exclusions — methods, status codes, routes, and response sizes
- [x] `conditional:required` middleware — `If-Match` enforcement with `412` / `428`, on every unsafe method, plus the `If-None-Match: *` create guard
- [x] Strong and weak ETag generation, with a configurable strategy
- [ ] `Last-Modified` / `If-Modified-Since` support alongside ETags
- [x] Model-derived validators, so an ETag comes from the record's version rather than a hash of the rendered body — including the pre-controller `304` short-circuit
- [ ] Eloquent API Resource and resource collection support
- [x] Laravel Octane safety, with no validator state leaking between requests
- [ ] Migration notes for projects coming from `werk365/etagconditionals`

## Testing

```bash
composer test          # static analysis, lint, type coverage, and the test suite
composer test:unit     # Pest only
composer analyse       # PHPStan
composer lint          # Pint
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laravel Conditional Requests! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Mitchell Williams](https://github.com/mitchello77)
- [All Contributors](../../contributors)

Prior art: [`werk365/etagconditionals`](https://github.com/365Werk/etagconditionals) mapped out this territory for Laravel first, and is worth a look if you need something available today.

## License

Laravel Conditional Requests is open-sourced software licensed under the [MIT license](LICENSE.md).
