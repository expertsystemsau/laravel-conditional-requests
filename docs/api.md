# Public API and stability

## What this page is

This is the list Semantic Versioning covers from `v1.0.0` onward: every symbol below keeps its name and its signature until a major release changes it deliberately, and the changelog says so. **Anything absent from this page is internal and may change in any release, including a patch.**

It is not a promise in prose. `tests/Feature/PublicApiTest.php` reflects the actual surface and fails when the two disagree — see [How the freeze is enforced](#how-the-freeze-is-enforced).

## Covered by semver

### Contracts

All four are implementable by consumers, so **every method signature is frozen and adding a method to any of them is a major version**. That is the strictest commitment on this page and the one most easily broken by accident: a new method on an interface breaks every implementation in every application.

| Symbol | Frozen |
| --- | --- |
| `Contracts\ValidatorStrategy` | `fromResponse(Request $request, Response $response): ?Validator` |
| `Contracts\RequestValidatorStrategy` | extends `ValidatorStrategy`; adds `fromRequest(Request $request): ?Validator` and `targetExists(Request $request): ?bool` |
| `Contracts\LockableValidatorStrategy` | extends `RequestValidatorStrategy`; adds `lockTarget(Request $request): ?Model` and `lockAndRefresh(Request $request, Model $target): ?Model` |
| `Contracts\ProvidesConditionalValidator` | `conditionalValidator(Request $request): ?Validator` |

The inheritance between the three strategy contracts is frozen too: a `LockableValidatorStrategy` is a `RequestValidatorStrategy` is a `ValidatorStrategy`, and code may rely on that.

### The value object

`Validators\Validator` is `final`, so there is no protected surface to freeze.

| Symbol | Frozen |
| --- | --- |
| `Validator::__construct()` | `(string $etag, bool $weak = false, ?DateTimeInterface $lastModified = null)` |
| `Validator::$etag` | `public readonly string` — the bare tag, no quotes and no weakness prefix |
| `Validator::$weak` | `public readonly bool` |
| `Validator::$lastModified` | `public readonly ?DateTimeImmutable` — floored to the second, in UTC |
| `Validator::header()` | `(): string` — the tag as it appears on the wire |

A new **trailing optional** constructor argument is additive rather than breaking, and is the mechanism `lastModified` arrived by. Any future field would use the same one.

### The model trait

`Concerns\HasConditionalValidator`.

| Symbol | Frozen |
| --- | --- |
| `conditionalValidator(Request $request): ?Validator` | public — the contract implementation |
| `conditionalVersionColumns(): list<string>` | protected — the documented extension point for "which column means this row changed" |
| `conditionalLastModifiedColumn(): ?string` | protected — the documented extension point for the date column |

Those two protected methods are frozen precisely because they are documented as things to override. Everything private in the trait is not.

### The strategy registry

`ConditionalRequests`, resolved from the container.

| Symbol | Frozen |
| --- | --- |
| `ConditionalRequests::extend()` | `(string $name, Closure $resolver): void` |
| `ConditionalRequests::strategy()` | `(string $name): ValidatorStrategy`, throwing `InvalidArgumentException` for an unregistered name |

**There is no facade.** `Facades\ConditionalRequests` was removed before `v1.0.0` was tagged, along with its `extra.laravel.aliases` entry, so the package claims no class alias in your application's root namespace. Inject the registry into a service provider's `boot()` instead, which Laravel resolves from the container:

```php
public function boot(ConditionalRequests $conditional): void
{
    $conditional->extend('revision', fn (): ValidatorStrategy => new RevisionStrategy);
}
```

That is shorter than the facade call it replaces, it is typed, and it cannot be reached from a controller — which is the point, because the registry is a container singleton ([H15](hazards.md#h15)).

### The built-in strategies

Both are `final readonly`, so they can be constructed and delegated to but not extended. Decoration is the supported way to change what one does — see [Wanting a different lock](writes.md#wanting-a-different-lock).

| Symbol | Frozen |
| --- | --- |
| `Validators\BodyHashStrategy` | the class name; `__construct(string $algorithm = 'xxh128', bool $weak = false)`; `fromResponse()` from `ValidatorStrategy` |
| `Validators\ModelStrategy` | the class name; `__construct(bool $weak = false, bool $lastModified = true)`; every method of `LockableValidatorStrategy` |
| the strategy name `body` | what a route and the `strategy` config key actually reference |
| the strategy name `model` | as above |

`ModelStrategy::lockingQuery()` is **not** covered: it is marked `@internal` and may change or disappear.

### The middleware

| Symbol | Frozen |
| --- | --- |
| the alias `conditional` | registered by the service provider on every boot |
| the flags | `body`, `model`, any name you register, plus the reserved words `required` and `lock` |
| flag order-independence | `conditional:required,lock` and `conditional:lock,required` are the same route |
| `Http\Middleware\Conditional` | the class name, so it can be named in `withoutMiddleware()` and in a kernel-global registration |
| `Conditional::handle()` | `(Request $request, Closure $next, string ...$flags): Response` |

**The middleware's constructor signature is explicitly not frozen.** It gained parameters in three consecutive pre-1.0 releases and is always resolved from the container, so nothing should construct it by hand. Keeping it out of the freeze is what lets the middleware keep growing without a major version; it is marked `@internal` so the snapshot test agrees.

### Exceptions

All three extend a Symfony `HttpException` subclass, which is what an application should catch and what its exception handler already renders.

| Symbol | Frozen |
| --- | --- |
| `Exceptions\PreconditionFailedException` | `412`; extends `Symfony\…\PreconditionFailedHttpException`; `MESSAGE_KEY` |
| `Exceptions\PreconditionRequiredException` | `428`; extends `Symfony\…\PreconditionRequiredHttpException`; `MESSAGE_KEY` |
| `Exceptions\LockTimeoutException` | `503`; extends `Symfony\…\ServiceUnavailableHttpException`; `MESSAGE_KEY`; `__construct(string $message = '', ?Throwable $previous = null, int|string $retryAfter = 1)` |

The parent classes are part of the promise. Code catching `PreconditionFailedHttpException` catches this package's `412`, and that will not stop being true.

### Configuration keys

All nine, by name and by meaning: `enabled`, `strategy`, `hash`, `weak`, `last_modified`, `max_response_bytes`, `methods`, `exclude`, `lock_timeout`. The namespace `laravel-conditional-requests` is frozen with them. See [`configuration.md`](configuration.md#reference).

**Their defaults are not frozen.** A default may change in a minor release when the current one turns out to be wrong, and the changelog will say so. The key names and what each governs are what you can build on.

### Publish tags and translation keys

| Frozen | Values |
| --- | --- |
| publish tags | `laravel-conditional-requests`, `-config`, `-lang`, `-assets` |
| translation keys | `precondition_failed`, `precondition_required`, `lock_timeout`, under the `laravel-conditional-requests::messages` namespace |

## Internal — may change in any release

Each of these carries `@internal` in its source, and the test suite asserts that the two lists partition the package with nothing falling between them.

- `Http\Middleware\Flags` — the parsed middleware parameter list. The public surface is the alias and its flags.
- `Preconditions\PreconditionEvaluator` — the RFC 9110 §13 comparisons. The public surface is the HTTP behaviour they produce.
- `Preconditions\PreconditionOutcome` — the enum those comparisons return.
- `Locking\LockWait` — the transaction and timeout plumbing. The public surface is `lock_timeout`, the `503`, and `LockTimeoutException`.
- `ModelStrategy::lockingQuery()` — public only because it is the seam a grammar test needs. Decorate the strategy instead.
- `Conditional::__construct()` — resolved from the container, and expected to keep changing.
- `ConditionalRequestsServiceProvider` — nothing on it is callable API. The class *name* is stable, because auto-discovery names it and an application may register it by hand; the wiring inside is not.
- Every private and protected member not named above.

## How the freeze is enforced

`tests/Feature/PublicApiTest.php` walks `src/`, reflects every class that does not carry `@internal`, renders each public method, constant and property as a signature string, sorts the result, and compares it to a literal list in the same file. Any addition, removal, or signature change fails the suite.

The same file asserts the other half: every class that contributes nothing to that list **does** carry `@internal`. Together the two assertions partition the package, so a new class cannot be neither frozen nor internal.

`tests/ArchTest.php` asserts that every class in the package is `final` and that everything in `Contracts` is an interface — the two structural properties the rest of the freeze assumes.

Five further examples in `PublicApiTest.php` freeze what reflection of `src/` cannot see: the nine configuration keys in file order, the middleware alias with the two built-in strategy names, the four publish tags, the three translation keys, and the strategy contract inheritance chain — which the signature snapshot records no `extends` relationships for, and which the promise above depends on.

Changing the frozen list is not difficult. It is *deliberate* — which is the whole point. A contributor who has to edit that list has been told, at the moment they broke the contract, that they broke it.

## Non-goals

These are not oversights and are not on a roadmap.

- **`Cache-Control` / `max-age` management.** A separate concern with existing packages, and one that conflicts with this one if both try to own the header.
- **Server-side response caching.** This package validates; it does not store. Nothing here keeps a copy of a response.
- **`Range` / `If-Range`.** A permanent non-goal.
- **Client-side HTTP caching helpers.** Out of scope in both directions — this is server middleware.
- **Eloquent API Resource and resource collection support.** Carried on the pre-1.0 roadmap and never designed. A resource is a *representation*, so the honest answer already exists: fold the representation into the tag by overriding `conditionalValidator()`, which is what [`reads.md`](reads.md#what-a-model-derived-tag-is-scoped-to) documents. If a first-class integration is ever built it will be additive.
