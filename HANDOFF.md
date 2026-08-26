---
handoff_version: "1"
source: xsys-handoff
mode: full
generated_at: "2026-08-26T16:47+10:00"
title: "v1.0.0 released to Packagist"
status: ready-for-review
branch: "main"
head_sha: "a223b125804bbdc7d9fac587de65d223379a43a2"
dirty_files: 2
diff_digest: "5cd8bd441b1d"
ticket_key: "none"
repo: "laravel-conditional-requests"
submodules: []
next_step: "Nothing is blocking. Next session: decide whether to keep the two scratch apps in ~/projects, and see Warnings before running composer analyse locally."
---

# Handoff: v1.0.0 released to Packagist

## Goal

Smoke-test `expertsystemsau/laravel-conditional-requests` on a real Laravel
application, then tag and publish v1.0.0. Both are done. This handoff exists so
the next session does not re-derive the release state or repeat the three things
that wasted time.

## Completed

- [x] All six `.github/RELEASING.md` pre-flight gates passed, then steps 2–6.
- [x] **v1.0.0 tagged and released** — `git tag v1.0.0` at `0a1e7af`, GitHub
      release named exactly `v1.0.0`, full release, body pasted from the
      changelog rather than generated.
- [x] **Live on Packagist.** The package was already submitted (2026-08-25) and
      the GitHub App is working: `v1.0.0` appeared at 06:43:18 UTC, seconds after
      the tag push, and `dev-main` tracks `main` automatically. No manual
      "Update" click is needed for future tags.
- [x] All six README badges render. Packagist shows `php: ^8.3`,
      `illuminate/* ^12.0||^13.0`, `symfony/http-foundation ^7.2||^8.0`.
- [x] `1.x` branch created from the tag and pushed, making the `'*.x'` workflow
      trigger real.
- [x] **Fixed a CI failure that would have shipped.** `bdb0fde` failed every
      `tests` leg at PHPStan — see Failed Approaches. Fixed in `7a016bf`.
- [x] Clean-app smoke matrix: **49/49**, run three times — `artisan serve` on
      SQLite, nginx + php-fpm on MySQL 8.4.11, Octane/FrankenPHP on MySQL.
- [x] `composer test:lock` — 5 passed, 0 skipped, on MySQL **and** PostgreSQL
      16.15, locally and in the `locking` workflow.
- [x] Concurrency proved both ways. 240 concurrent writes to a
      `conditional:required,lock` route: version delta == 2xx count exactly,
      zero 503, zero unexpected. With a deliberate 250 ms window, the unlocked
      route answered 23 requests `2xx` while the version advanced only 6 — 17
      committed writes lost; the locked route in the same window lost none.
- [x] `@@session.innodb_lock_wait_timeout` verified under Octane on one
      persistent MySQL connection: 50 → 5 inside the guarded transaction → 50
      after, and 50 after a `412` too.
- [x] `304` under php-fpm carries **no `Content-Type`** — the one check
      `artisan serve` could never make. The Warning about it is now closed.
- [x] No Octane state bleeding: 80 interleaved requests, two records, one
      worker, zero tag leakage.
- [x] Documentation audit re-run (pre-flight 5) — clean. All 18 public symbols
      exist and are documented, all 9 config defaults match the code, publish
      tags match the provider, all 18 hazard anchors resolve, every ETag sample
      is 32 hex chars, and the documented custom-strategy example was built in
      the clean app and produces exactly `hash('xxh128', version)`.
- [x] `export-ignore` verified **in the published Packagist dist**: no `tests/`,
      `workbench/`, `HANDOFF.md`, `.ai-skills/`, `CLAUDE.md`, `AGENTS.md`, or CI
      config reaches consumers.
- [x] Post-release verification from Packagist in a fresh app: `v1.0.0`
      resolves, provider auto-discovered with zero manual entries, config
      publishes, `->middleware('conditional')` returns an ETag then a `304`
      with a zero-length body.

## Not Yet Done

- [ ] Nothing is blocking, and nothing is known broken.
- [ ] Optional: decide the fate of `~/projects/lcr-smoke` (scratch app with the
      package symlinked by path, Octane installed, MySQL `lcr_smoke` database,
      and deliberately racy routes) and `~/projects/lcr-release-check` (installs
      v1.0.0 from Packagist). Neither is referenced by the package. The
      `lcr_smoke` and `conditional_requests` MySQL/PostgreSQL databases are also
      leftovers.
- [ ] Optional: `testbench.yaml` and `public/.gitkeep` still ship to consumers.
      Harmless, pre-existing, and not changed here because nobody asked.

## Failed Approaches (Don't Repeat These)

- **Trusting a green local `composer analyse`.** `bdb0fde` failed all 18 `tests`
  legs on `Parameter #3 $strategy of method Conditional::locked() expects
  LockableValidatorStrategy, RequestValidatorStrategy given`. The call was
  correct at runtime — the guard at the top of `write()` throws for a `lock`
  route whose strategy is not lockable — but proving it at the call site needs
  the analyser to carry that condition across ~85 lines, which PHPStan does only
  from 2.2. `prefer-lowest` resolves larastan `^3.9` → 3.9.0 → phpstan 2.1.32,
  which cannot; locally the constraint resolves to 2.2.9, which can. Worse,
  fail-fast then cancelled the prefer-stable legs, so the run summary implicated
  legs that had found nothing. **Adding a second `instanceof` at the call site
  is not the fix** — 2.2 reports that as an already-narrowed type, so the two
  versions demand opposite code. The fix (`7a016bf`) assigns the narrowed
  strategy to a nullable local inside the guard and branches on that.
  `.github/RELEASING.md` pre-flight step 1 now carries the scratch-copy recipe
  that reproduces the prefer-lowest resolution locally.

- **Believing the previous handoff's `lerd` diagnosis.** It recorded the
  sub-path `404`s as specific to the old testbed site and blamed lerd's vhost.
  Both are wrong. They reproduce on a brand-new site, and the cause is not lerd
  at all: **this machine's LAN DNS claims `.test` names.** `lcr-smoke.test`
  resolved to `fe80::d0a2:ecff:fe11:2456 lcr-smoke.test.lan.mitch.host`, so
  those requests were served by a different host on the network — that is where
  the openresty `404` and a `Last-Modified: 2023` page came from. Use
  `curl --resolve lcr-smoke.test:80:127.0.0.1` (or a `Host:` header against
  `127.0.0.1`) and nginx + php-fpm works fine. Two further traps behind it: the
  nginx container had a stale mount and needed a restart to see a newly created
  site root, and from inside the php-fpm container the database is `lerd-mysql`,
  not `127.0.0.1`.

- **Measuring a blank `If-Match` with `curl -H 'If-Match: '`.** curl *suppresses*
  a header given an empty value, so that request carries no precondition at all
  and correctly gets `428` on a `required` route — it looks like the package
  ignoring a blank header. Use `-H 'If-Match;'` for a genuinely empty value; it
  returns `412` with the record intact, as does the zero-member list
  `-H 'If-Match: ,'`.

- **Expecting `changelog-updater-action` to move the `[Unreleased]` body.** It
  inserts the release body it is handed as a new version section and leaves the
  existing `[Unreleased]` content in place, so publishing duplicated all 109
  lines. It also builds the heading links by appending to the URL the
  `[Unreleased]` heading already carried, emitting
  `…/commits/main/compare/v1.0.0...HEAD` and
  `…/commits/main/compare/main...v1.0.0` — both 404. Fixed by hand in `a223b12`,
  and `.github/RELEASING.md` §2 now describes the real behaviour instead of
  blaming a pre-written heading.

- **Running PHPStan on this machine without `PHPSTAN_TURBO=0`.** See Warnings.

## Key Decisions

| Decision | Rationale |
|---|---|
| Fix the narrowing in code rather than raise the larastan floor | `prefer-lowest` exists to catch exactly this class of problem; raising the floor would have hidden it and narrowed the matrix contributors can use. |
| Keep the whole release under `[Unreleased]` with no version heading | `.github/RELEASING.md` §2's contract — the action owns version headings. It duplicates rather than moves, so the post-publish tidy is now documented as expected work, not as a mistake. |
| `[v1.0.0]` heading links to `releases/tag/v1.0.0`, not a compare view | A first release has no earlier tag to compare against. |
| Commit `HANDOFF.md` and `.ai-skills/` but `export-ignore` both | A handoff a teammate cannot see is half a handoff, but neither belongs in the archive Packagist serves. Verified absent from the real dist. |
| Prove the lost update with an artificial 250 ms window | At natural speed the unlocked route happened to hold, which proves nothing. RELEASING §1.4 requires confirming it does *not* hold, so the window makes the guarantee's absence observable rather than a matter of luck. |

## Current State

**Working** — `main` at `a223b12`, pushed. `tests` and `locking` green. v1.0.0
live on Packagist and installable.

**Broken** — nothing known.

**Uncommitted changes** — `HANDOFF.md` and `.ai-skills/` only (this handoff and
its archive). No source changes outstanding.

## Verification

| Command | Result |
|---|---|
| `tests` workflow on `a223b12` | 24/24 legs green, including 12 `windows-latest` |
| `locking` workflow on `a223b12` | mysql 5 passed, pgsql 5 passed; the workflow's own guard fails on a skip, so this is proof it executed |
| `composer test:lock` locally, MySQL 8.4.11 | 5 passed, 0 skipped |
| `composer test:lock` locally, PostgreSQL 16.15 | 5 passed, 0 skipped |
| `vendor/bin/pest --parallel` | 444 tests, 439 passed, 5 skipped |
| `vendor/bin/pint --parallel --test` | clean |
| `vendor/bin/pest --type-coverage --min=100` | 100.0% |
| PHPStan level 7 (`PHPSTAN_TURBO=0`, single process) | 0 errors |
| PHPStan 2.1.32 + larastan 3.9.0 + Laravel 12.52.0 scratch copy | 0 errors (reproduces the CI failure on the pre-fix file) |
| 49-case smoke matrix × 3 SAPIs | 49/49 each |
| Concurrency, `lock`, MySQL, 240 requests | delta == 2xx, 0 × 503, 0 unexpected |
| Post-release install from Packagist | v1.0.0 resolves; ETag then `304`, 0 body bytes |

## Files to Know

| File | Why It Matters |
|---|---|
| `.github/RELEASING.md` | The release procedure, now corrected in two places: pre-flight 1 carries the prefer-lowest reproduction, and §2 describes what the changelog action actually does. Read before the next tag. |
| `src/Http/Middleware/Conditional.php` | `write()` assigns `$lockable` inside the `lock` guard and branches on it at the call to `locked()`. That shape is load-bearing for PHPStan 2.1.x — see Failed Approaches before "simplifying" it. |
| `CHANGELOG.md` | `[Unreleased]` is deliberately empty; the v1.0.0 body includes a "Verified for this release" section carrying the measured numbers RELEASING §1.4 requires. |
| `tests/Unit/DocumentationLinksTest.php` | Validates every internal doc link, including explicit `<a id="…">` anchors and GitHub's real slug rule. Better than an ad-hoc script — use it rather than writing another. |
| `docs/hazards.md` | 18 entries, stable anchors, all resolving. |

## Resume Instructions

There is no blocked work. If you are picking this up to do the next release:

1. Read `.github/RELEASING.md` first — it is the procedure and it is now
   accurate.
2. Before trusting a local `composer analyse`, reproduce the `prefer-lowest`
   resolution using the recipe in pre-flight step 1.
   - Expected: 0 errors on phpstan 2.1.32 as well as on the newest.
   - If it errors where the local run did not, the code depends on newer
     inference — restructure it, do not raise the floor.
3. Expect to hand-tidy `CHANGELOG.md` after publishing: empty `[Unreleased]`,
   and repair both heading links.
   - Expected: `…/compare/vX.Y.Z...main` and a working version-heading link.

If instead you are running the smoke matrix again, the scripts are gone with the
session but the shape is in the v1.0.0 changelog body, and
`~/projects/lcr-smoke` still has the routes (including the racy pair).

## Setup Required

- MySQL and PostgreSQL are provided by `lerd` on `127.0.0.1:3306` and
  `127.0.0.1:5432`. Credentials are in the lerd service definitions, not here.
- The row-lock suite reads `CONDITIONAL_LOCK_DRIVER`, `_HOST`, `_PORT`,
  `_DATABASE`, `_USERNAME`, `_PASSWORD`. Names only; the skip message lists them
  and `.github/workflows/locking.yml` shows the CI values.
- Serving through lerd needs `curl --resolve …:80:127.0.0.1`; see Failed
  Approaches for why.

## Edge Cases & Error Handling

Unchanged from v1.0.0 — the register in `docs/hazards.md` is current and its
18 anchors all resolve. Nothing found during the smoke test contradicted it.

## Warnings

- **`composer analyse` cannot run unmodified on this machine.** PHP here is musl
  (Alpine), but PHPStan 2.2.9 selects its `linux-gnu-x86_64` turbo extension and
  the worker dies with `Dynamic loading not supported`, exit 255 and no output.
  Use `PHPSTAN_TURBO=0 php -d memory_limit=-1 vendor/phpstan/phpstan/phpstan.phar
  analyse --no-progress --debug -c phpstan.neon.dist`. This is environmental, not
  a repository fault, and CI is unaffected.
- **`build/phpstan` is the result cache and it will happily report a stale pass.**
  Clear it when a result surprises you.
- **The 5 skipped tests are still not a defect.** SQLite's `compileLock()` emits
  no SQL, so mutual exclusion is unprovable there.
  `tests/Feature/LostUpdateTest.php` deliberately asserts the harness *cannot*
  see a row lock. Do not delete it.
- **Place `conditional` after anything that can reject the request.** The
  short-circuit skips everything the controller would have decided, including
  per-record authorization. `docs/hazards.md#h1`.
- **PHP 8.3 is the floor and cannot be checked locally** (local is 8.5). Write
  `(new Foo)->bar()`, never `new Foo()->bar()`. CI's `prefer-lowest` leg is the
  only real guard.
- **`HasConditionalValidator` has zero type-coverage signal** — the Pest plugin
  filters out any file containing the string `trait ` before analysis. PHPStan
  level 7 still covers it.
- **Packagist auto-sync is live.** Any tag you push publishes itself. There is no
  longer a manual step protecting you from a premature tag.
