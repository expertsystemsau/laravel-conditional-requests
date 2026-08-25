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
> **Pre-release — under active development.** The read path described below ships and is tested, model-derived validators and the pre-controller `304` short-circuit included. The write path (`conditional:required`, `412`, `428`), the `Last-Modified` family, and locking are not implemented yet — they are marked on the [roadmap](#roadmap) below. Nothing is stable until `v1.0.0`.

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
> Under a strategy that derives its validator from the rendered body — `body`, and any custom strategy implementing only `ValidatorStrategy` — a `HEAD` request is handed to your controller as a `GET` while the validator is computed, so it gets the same `ETag` and can still trigger a `304`; the body is emptied again before the response goes out. In practice this is invisible, since Laravel already routes `HEAD` to the `GET` action — but controller code that inspects `$request->method()` or `isMethod('HEAD')` will see `GET` for the duration of that call. Under a request-derived strategy such as `model` there is no body to hash, so nothing is mutated and the controller sees the `HEAD` the client sent.

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

> [!WARNING]
> `required` and `lock` are reserved words rather than strategy names, and both already select `model` today — that much is live, even though the write path they belong to is not. Putting `conditional:required` on a `GET` route ahead of `v0.3` therefore switches it from `body` to `model`, turning on the pre-controller short-circuit and [the authorization hazard that comes with it](#model-derived-validators) — and on a route whose bound model does not implement `ProvidesConditionalValidator`, leaving it with no `ETag` at all.

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

        // Returning null leaves the response untouched.
        return $article ? new Validator((string) $article->revision) : null;
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
> Call `extend()` from a service provider's `boot()` method only. The registry is a container singleton, so calling it from a controller, a route closure, or any other request handler permanently mutates shared state — under Laravel Octane that means for the whole worker, for every subsequent request it serves.

A custom strategy can take part in the short-circuit too. Implement `RequestValidatorStrategy`, which extends `ValidatorStrategy` with one method:

```php
public function fromRequest(Request $request): ?Validator;
```

Answer from the request alone and the middleware will ask before the controller runs; return `null` and it falls back to `fromResponse()` afterwards. A strategy that implements only `ValidatorStrategy` keeps working exactly as it did — it is simply never asked early.

Answering early also suppresses the streamed, binary, and size-ceiling checks for that response, on the grounds that a validator already in hand cost no body read. The suppression follows the answer rather than the interface: on the `null` path `fromResponse()` is asked the ordinary way and faces every one of those rules, so a streamed, binary, or oversized response simply goes untagged there — the same outcome `body` gives it.

> [!WARNING]
> Every `RequestValidatorStrategy` carries the authorization caveat, not just `model`. Whenever `fromRequest()` answers and the client's tag matches, the `304` goes out before anything declared after `conditional` runs — `can:`, `signed`, subscription and feature gates, and any check inside the controller action. Place `conditional` after every middleware that can reject the request, and see the [`model` warning above](#model-derived-validators) for what that costs if you do not.

### Proxies and content coding

A validator identifies one specific set of bytes, and a reverse proxy that compresses your response changes those bytes. nginx drops the `ETag` outright when it gzips; Apache appends `-gzip` to it. Either way the tag the client holds is not the tag this middleware computed, so `If-None-Match` stops matching and you never see a `304`.

If `304`s work in local development and never in production, check the proxy first — `curl -sI -H 'Accept-Encoding: gzip' <url>` against the proxy and against the app directly will show the difference immediately.

## Design contract — not yet implemented

Everything in this section is the design contract for a later release and is **not implemented yet**; see the [roadmap](#roadmap).

### Conditional writes (lost update protection)

Require the client to state which version it believes it is modifying. A stale token is rejected with `412`; a missing one with `428`.

```php
Route::patch('/articles/{article}', UpdateArticle::class)
    ->middleware('conditional:required');
```

> [!NOTE]
> The flag itself already parses today, and already selects the `model` strategy — see [Choosing a validator strategy](#choosing-a-validator-strategy). What is missing is the write path behind it: `If-Match` is not evaluated, and no `412` or `428` is ever returned. On a `GET` route it is not inert.

```http
PATCH /articles/42
If-Match: "d41d8cd98f00b204"
→ 200 OK                    # still current, write applied

PATCH /articles/42
If-Match: "d41d8cd98f00b204"
→ 412 Precondition Failed   # someone else got there first

PATCH /articles/42
                            # no If-Match header at all
→ 428 Precondition Required
```

`428` is the piece most implementations skip. Without it a client can simply omit the header and go straight back to clobbering other people's writes — the protection is opt-out by default. `conditional:required` makes it opt-in-by-force for the routes you choose.

## Header reference

| Request header | Applies to | On match | On mismatch | Ships |
| --- | --- | --- | --- | --- |
| `If-None-Match` | reads | `304 Not Modified` | `200 OK` with body | yes |
| `If-Modified-Since` | reads | `304 Not Modified` | `200 OK` with body | no |
| `If-Match` | writes | write proceeds | `412 Precondition Failed` | no |
| `If-Unmodified-Since` | writes | write proceeds | `412 Precondition Failed` | no |
| *(absent)* | writes, when required | — | `428 Precondition Required` | no |

## Roadmap

- [x] `conditional` middleware — response validators and `304` short-circuiting
- [x] Configurable exclusions — methods, status codes, routes, and response sizes
- [ ] `conditional:required` middleware — `If-Match` enforcement with `412` / `428`
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
