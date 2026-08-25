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
> A `HEAD` request is handed to your controller as a `GET` while the validator is computed, so it gets the same `ETag` and can still trigger a `304`; the body is emptied again before the response goes out. In practice this is invisible, since Laravel already routes `HEAD` to the `GET` action — but controller code that inspects `$request->method()` or `isMethod('HEAD')` will see `GET` for the duration of that call.

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

### Model-derived validators

The `model` strategy takes the validator from the route-bound record rather than from the rendered body. Because the record's version is known **before** the controller runs, a request whose `If-None-Match` already matches is answered with `304` without executing the route action — no serialization, and with implicit route-model binding (the only wiring documented here) one query fewer than the long way, not zero: `SubstituteBindings` still issues the binding query before `conditional` runs.

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
> A matching tag skips **everything the controller would have decided**, per-record authorization included. A client holding a still-valid tag keeps receiving `304` after its access to that record is revoked, or for a record the controller would otherwise have hidden from it — the middleware answers before any of that logic runs. This is inherent to answering before the controller, not a defect. Mitigate it by placing `conditional` **after** your authorization middleware, so a request that should be rejected never reaches the strategy at all, and by knowing that a per-record check made *inside* the controller action itself is skipped entirely on a hit — it has to live in middleware to apply.

The default tag fingerprints the record's table, its key, and its version — an explicit `version` column when the model has one, otherwise the raw `updated_at` value. The table is in there so two records with the same id in different tables can never share a tag. A record with no version at all — one that has never been saved — produces no validator, and the response is left untouched.

Model-derived validators are **strong**. RFC 9110 §13.1.1 requires strong comparison for `If-Match`, so a weak tag could never satisfy the write-path guard.

> [!IMPORTANT]
> `conditional` must run **after** route model binding. Inside the `api` or `web` middleware group that is already true, since `SubstituteBindings` belongs to both. On a route that has not had its bindings substituted yet — kernel-global placement, or a hand-written middleware list that puts `conditional` first — the strategy finds no record before the controller runs and the request quietly takes the ordinary path: the `ETag` is still attached on the way out, but the controller runs and nothing is saved.

> [!NOTE]
> `updated_at` is stored to the second by default, so two writes inside the same second produce the same tag. Add a `version` column, or widen the column's precision, on resources that change that fast.

> [!NOTE]
> A short-circuited `304` cannot carry headers your controller or downstream middleware would have set — an application `Cache-Control`, `Vary`, `Content-Location`, and the like never run on a hit, because nothing that would set them does. RFC 9110 §15.4.5 says a `304` *should* carry them. The long way round — controller runs, `304` decided afterwards — carries them exactly as before; only the pre-controller short-circuit skips them.

When a route binds more than one conditional record, the **first** route parameter implementing the contract wins — parameters keep their declaration order, so on `/articles/{article}/comments/{comment}` the article is the target.

#### Varying the tag by representation

A strong validator asserts one specific representation. If the same record is served in more than one shape — content negotiation, sparse fieldsets, `?include=` — fold that input into the tag. That is what the `$request` argument is for:

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
