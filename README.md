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

## Requirements

- PHP 8.3, 8.4, or 8.5
- Laravel 12.x or 13.x

## Installation

Install the package via Composer:

```bash
composer require expertsystemsau/laravel-conditional-requests
```

The service provider is auto-discovered. No further setup is required to get started.

## Quickstart

**A conditional read.** Apply the middleware to a route that returns a cacheable representation. The response gets a validator, and a matching subsequent request is answered `304 Not Modified` with an empty body.

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

**A guarded write.** Add `required` to a route that changes something, and the client has to say which version it believes it is modifying. Add the contract and the trait to the model so the middleware can ask the record its version:

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

That is the whole surface. [`docs/reads.md`](docs/reads.md) and [`docs/writes.md`](docs/writes.md) cover everything else.

## Before you put this in front of users

Six things can bite you. Each is one click away, and the [full register](docs/hazards.md) has twelve more with a scan table.

- A client that holds a valid tag keeps getting `304` after you revoke its access — anything declared after `conditional` does not run on a hit. → [H1](docs/hazards.md#h1)
- A `version` column that does not change on every write freezes the tag and serves `304` against content that changed. → [H3](docs/hazards.md#h3)
- Under `model` the tag is scoped to the record, not to the viewer, the tenant row, or the `Accept` header — one client's tag gets honoured for another. → [H5](docs/hazards.md#h5)
- A short-circuited `304` carries the framework's `Cache-Control`, and a cache adopts it: your `public, max-age=60` becomes `private, no-cache` permanently. → [H6](docs/hazards.md#h6)
- Any middleware that changes the response body *outside* `conditional` makes the tag describe bytes nobody received — a frozen CSP nonce, a stale CSRF token. → [H7](docs/hazards.md#h7)
- `lock` runs your controller inside a transaction: jobs dispatch before the commit, and returning an error response commits rather than rolls back. → [H9](docs/hazards.md#h9)

## Header reference

| Request header | Applies to | On match | On mismatch | Ships |
| --- | --- | --- | --- | --- |
| `If-None-Match` | reads | `304 Not Modified` | `200 OK` with body | yes |
| `If-None-Match: *` | writes | `412 Precondition Failed` | write proceeds | yes |
| `If-None-Match` (concrete tag, weak comparison) | writes, without `required` | `412 Precondition Failed` | write proceeds | yes |
| `If-None-Match` (concrete tag) | writes, when required | `428 Precondition Required` | `428 Precondition Required` | yes |
| `If-Modified-Since` | reads | `304 Not Modified` | `200 OK` with body | yes |
| `If-Match` | writes | write proceeds | `412 Precondition Failed` | yes |
| `If-Unmodified-Since` | writes | write proceeds | `412 Precondition Failed` | yes |
| *(absent)* | writes, when required | — | `428 Precondition Required` | yes |

## Documentation

- [Conditional reads](docs/reads.md) — strategies, the pre-controller short-circuit, `Last-Modified`, custom strategies
- [Conditional writes](docs/writes.md) — `If-Match`, `412`, `428`, the create guard, `lock`
- [Placement and ordering](docs/placement.md) — where `conditional` goes, and what changes if it goes elsewhere
- [Configuration](docs/configuration.md) — every key and what it governs
- [Hazards](docs/hazards.md) — the register, with a scan table
- [Public API and stability](docs/api.md) — what semver covers

## Coming from `werk365/etagconditionals`?

[The migration guide](docs/migrating-from-werk365.md) maps `setEtag`, `ifNoneMatch`, `ifMatch` and the `etag` group onto `conditional` and its flags, lists the behaviour changes you will hit on day one, and says plainly when not to bother.

## Stability

`v1.0.0` and later follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html). [`docs/api.md`](docs/api.md) names exactly what that covers — every contract, the trait, the middleware alias and its flags, the config keys, the exceptions. Anything not on that list is internal and may change in any release.

The list is not a promise in prose: `tests/Feature/PublicApiTest.php` reflects the actual surface and fails when it differs from the frozen one.

## Testing

```bash
composer test          # static analysis, lint, type coverage, and the test suite
composer test:unit     # Pest only
composer test:lock     # row-lock contention; needs MySQL or PostgreSQL (see below)
composer analyse       # PHPStan
composer lint          # Pint
```

`composer test:lock` is not part of `composer test`, so the suite stays runnable with nothing but PHP and SQLite. It proves that a competing session's row lock forces a `503`, which SQLite cannot demonstrate at all — `lockForUpdate()` compiles to nothing there. Point it at a database you can throw away:

```bash
CONDITIONAL_LOCK_DRIVER=mysql \
CONDITIONAL_LOCK_HOST=127.0.0.1 \
CONDITIONAL_LOCK_PORT=3306 \
CONDITIONAL_LOCK_DATABASE=conditional_requests \
CONDITIONAL_LOCK_USERNAME=root \
CONDITIONAL_LOCK_PASSWORD=secret \
composer test:lock
```

Without those variables every test in it skips, naming them. CI runs it against MySQL and PostgreSQL on every push and fails if it skipped.

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
