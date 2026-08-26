# Configuration

## Publishing

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

Nothing has to be published to use the package. The defaults below are merged at boot, so an application that never publishes the config file still gets every one of them.

## Reference

Nine keys, in the order they appear in `config/laravel-conditional-requests.php`.

| Key | Type | Default | Governs |
| --- | --- | --- | --- |
| `enabled` | `bool` | `true` | Master switch over **both** paths. `false` is a true pass-through: no validator is attached, the `HEAD` method is not mutated, and every lost-update guard in the application is removed with it. |
| `strategy` | `string` | `'body'` | Which registered strategy produces validators on a route that names none. A middleware flag always wins. An unregistered name is a per-request `500` → [H13](hazards.md#h13). Setting this to `'model'` **while `conditional` is registered as kernel-global middleware** inverts every write guard in the application → [H12](hazards.md#h12). |
| `hash` | `string` | `'xxh128'` | The algorithm the `body` strategy hashes with; any name `hash()` accepts. `model` derives its tag from the record and never reads this key. Not a security primitive → [H14](hazards.md#h14). |
| `weak` | `bool` | `false` | Emits `W/"…"` on both strategies. **Pairing this with a guarded write route throws a `LogicException`** — a weak validator can never satisfy `If-Match`, so the guard would be inverted rather than merely disabled. |
| `last_modified` | `bool` | `true` | Whether model-derived validators publish the record's modification date. Governs the header **and** the `If-Unmodified-Since` guard together: with it off, responses carry no date and a write offering only that header is refused with `412` rather than passing unguarded. |
| `max_response_bytes` | `int` | `1_048_576` | **Read path, body-derived validators only.** A larger response is passed through untagged rather than hashed. `0` or any non-positive value means unlimited. Suppressed entirely when the strategy already answered from the request. |
| `methods` | `list<string>` | `['GET', 'HEAD']` | **Read path only.** Which methods take part in validator attachment and the `304` comparison. Adding an unsafe method here does not attach validators to its responses, and removing one does not disable the write path. Case-insensitive — the middleware upper-cases them. |
| `exclude` | `list<string>` | `[]` | Route names or URI patterns, both accepting the usual `*` wildcard. Suppresses **both** paths, which makes this the key to reach for when a route must opt out entirely. Route-name patterns cannot be honoured on the write path under kernel-global placement → [H12](hazards.md#h12). |
| `lock_timeout` | `int` | `5` | Seconds a `lock` route waits for the row before answering `503`. Issued on PostgreSQL (`SET LOCAL lock_timeout`) and MySQL / MariaDB (`SET SESSION innodb_lock_wait_timeout`, restored afterwards); other drivers ignore it. `0` leaves the server's own setting alone — and PostgreSQL's own default is `0`, meaning wait for ever. |

A malformed value degrades rather than throwing, which saves a support round trip: a bare string where a list is documented is read as a single-element list rather than raising a `TypeError`, and a `null` or non-numeric `max_response_bytes` casts to `0` and therefore reads as unlimited.

## Publish tags

| Tag | Publishes | To |
| --- | --- | --- |
| `laravel-conditional-requests` | everything below | — |
| `laravel-conditional-requests-config` | `config/laravel-conditional-requests.php` | `config/laravel-conditional-requests.php` |
| `laravel-conditional-requests-lang` | `lang/` | `lang/vendor/laravel-conditional-requests` |
| `laravel-conditional-requests-assets` | `public/` | `public/vendor/laravel-conditional-requests` |

`public/` is currently empty, so the assets tag publishes nothing. It exists because the publish surface is frozen at `v1.0.0` and removing a tag later would be a breaking change for anyone whose deploy script names it — better a tag that does nothing today than one that disappears.

## Translation keys

`lang/en/messages.php` holds three keys, all of them message bodies for the write path's refusals. Publish the file with the `laravel-conditional-requests-lang` tag to reword them, or catch the exception in your own handler and render whatever you like.

| Key | Used by | Named on the class as |
| --- | --- | --- |
| `precondition_failed` | `PreconditionFailedException` (`412`) | `PreconditionFailedException::MESSAGE_KEY` |
| `precondition_required` | `PreconditionRequiredException` (`428`) | `PreconditionRequiredException::MESSAGE_KEY` |
| `lock_timeout` | `LockTimeoutException` (`503`) | `LockTimeoutException::MESSAGE_KEY` |

Each `MESSAGE_KEY` constant holds the fully-qualified key — `laravel-conditional-requests::messages.precondition_failed` and so on — which is what the middleware passes to the translator.
