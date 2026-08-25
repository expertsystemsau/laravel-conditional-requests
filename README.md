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
> **Pre-release — under active development.** The package skeleton, CI, and support matrix are in place; the middleware described under [Usage](#usage) is the design contract being built against and is **not implemented yet**. Nothing here is stable until `v1.0.0`. Watch the repo or the [changelog](CHANGELOG.md) for progress.

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

> [!NOTE]
> This section documents the **intended API**. It is the design contract for `v1.0.0` and is not yet implemented — see [Status](#status).

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

### Header reference

| Request header | Applies to | On match | On mismatch |
| --- | --- | --- | --- |
| `If-None-Match` | reads | `304 Not Modified` | `200 OK` with body |
| `If-Modified-Since` | reads | `304 Not Modified` | `200 OK` with body |
| `If-Match` | writes | write proceeds | `412 Precondition Failed` |
| `If-Unmodified-Since` | writes | write proceeds | `412 Precondition Failed` |
| *(absent)* | writes, when required | — | `428 Precondition Required` |

## Roadmap

- [ ] `conditional` middleware — response validators and `304` short-circuiting
- [ ] `conditional:required` middleware — `If-Match` enforcement with `412` / `428`
- [ ] Strong and weak ETag generation, with a configurable strategy
- [ ] `Last-Modified` / `If-Modified-Since` support alongside ETags
- [ ] Model-derived validators, so an ETag comes from the record's version rather than a hash of the rendered body
- [ ] Eloquent API Resource and resource collection support
- [ ] Configurable exclusions — methods, status codes, routes, and response sizes
- [ ] Laravel Octane safety, with no validator state leaking between requests
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
