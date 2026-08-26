# Releasing

What a maintainer does on release day, in order. Three of this repository's own
automations will produce a mess if a step is taken out of order, and each trap
is named at the step where it bites.

## 1. Pre-flight

Nothing is tagged until all six pass.

1. `composer test` green on `main` — PHPStan level 7, Pint, 100% type coverage,
   the full Pest suite, the public-API snapshot, and the documentation link
   test. **A green local run is not enough for PHPStan.** The constraint
   resolves to the newest analyser locally, and `prefer-lowest` pins an older
   one whose inference is weaker: on v1.0.0 the `tests` workflow failed every
   leg on an error no local run could produce, and fail-fast cancelled the
   prefer-stable legs so the summary named the wrong culprit. Read the workflow
   result on `main`, or reproduce the resolution in a scratch copy:

   ```bash
   git ls-files -z | tar --null -cf - -T - | tar -xf - -C /tmp/lowest
   cd /tmp/lowest
   composer require "laravel/framework:12.*" "orchestra/testbench:10.*" --no-interaction --no-update
   composer update --prefer-lowest --prefer-dist --no-interaction --no-scripts
   composer analyse
   ```
2. `composer test:lock` green against **both** MySQL and PostgreSQL, either
   locally with the `CONDITIONAL_LOCK_*` environment or by reading the
   `locking` workflow's last run on `main`. That workflow fails if the suite
   skipped, so a green run is proof it actually executed.
3. The whole suite green on `windows-latest`. `tests/Feature/LostUpdateTest.php`
   uses a **file-backed** SQLite database with two independent connections,
   which is the only way to prove the unlocked path loses a genuinely committed
   write — and Windows' SQLite file locking differs from Linux's, so two
   concurrent connections to one file can raise `database is locked`. Read the
   `tests` workflow's Windows legs on `main`. If that file is flaky there, gate
   it to non-Windows with a **loud** skip naming the reason, the same posture as
   `test:lock`. Do not weaken the test to make it pass everywhere.
4. The two manual verifications, both recorded in the release notes:
   - A genuinely concurrent load against a `conditional:required,lock` route on
     MySQL. The row's final version must equal the number of `2xx` responses,
     with every other request a `412` or a `503`. Then the same route without
     `lock`, confirming it does **not** hold.
   - A run with `PDO::ATTR_PERSISTENT`, or under Octane, confirming
     `@@session.innodb_lock_wait_timeout` is unchanged after a guarded request.
5. The documentation audit re-run — every claim in `README.md` and `docs/`
   checked against the symbol that makes it true.
6. `git tag -l` still empty, confirming this really is the first tag.

## 2. Prepare the changelog — first trap

**The action owns version headings; the repository owns `[Unreleased]`.**

`update-changelog.yml` fires on `release: types: [released]` and uses
`stefanzweifel/changelog-updater-action` to insert `github.event.release.body`
under `## [Unreleased]`, then commits to `main`. If the maintainer has *also*
written the `## v1.0.0` section by hand, the section appears twice.

`CHANGELOG.md` therefore ships with the whole release content under
`## [Unreleased]` and **no** `## v1.0.0` heading. That is deliberate. So:

- Copy the whole body of `## [Unreleased]` — everything below the heading, not
  including it.
- Paste it as the GitHub Release body in step 3.
- Publish. The action inserts it as `## v1.0.0` beneath `[Unreleased]` and
  commits to `main`.
- `git pull` before touching `main` again.
- Then change the `[Unreleased]` link from `commits/main` to
  `compare/v1.0.0...main` and commit that. Until the first tag exists there is
  nothing to compare against, which is why it points at the commit history now.

**The action inserts; it does not move.** This was learned the hard way on
v1.0.0. `changelog-updater-action` writes the release body it is handed in as a
new version section beneath `## [Unreleased]`, and it leaves whatever was already
under `[Unreleased]` exactly where it was. Because this repository ships the
whole release under `[Unreleased]`, publishing produces the content **twice** —
once under `[Unreleased]`, once under the new version heading. That is not a
mistake by the maintainer and pre-writing the heading is not what causes it.

So after publishing, and after `git pull`, expect to do all of this by hand in
one commit:

- Empty the `[Unreleased]` section, leaving the heading and no body.
- Repair both heading links. The action builds them by appending to whatever URL
  the `[Unreleased]` heading already carried, so with `commits/main` in place it
  emitted `…/commits/main/compare/v1.0.0...HEAD` and
  `…/commits/main/compare/main...v1.0.0` — both 404. They should be
  `…/compare/v1.0.0...main` for `[Unreleased]`, and for the first release
  `…/releases/tag/v1.0.0` for the version heading, since there is no earlier tag
  to compare against.

Two exceptions worth writing down:

- If branch protection on `main` requires pull requests, `git-auto-commit-action`
  cannot push. The workflow fails and it is easy to miss. In that case write the
  version heading by hand and expect the workflow to be red.
- Everything the action gets wrong is confined to `CHANGELOG.md`. Fix the file;
  nothing else is affected, and there is no need to re-cut the release.

## 3. Tag and release — second and third traps

```bash
git switch main && git pull
composer test
git tag -a v1.0.0 -m "v1.0.0"
git push origin v1.0.0
```

Then create the GitHub Release. Two things must be right:

- **The release name must be exactly `v1.0.0`.** `update-changelog.yml` passes
  `github.event.release.name` as `latest-version`, so the heading it writes into
  the changelog is whatever was typed in the title field. A title of
  "1.0.0 — first stable release" produces exactly that as a changelog heading.
- **Publish it as a full release**, not a draft and not a pre-release. The
  workflow triggers on `types: [released]`: a draft never fires it, and a
  pre-release fires `prereleased` instead. A pre-release publishes to Packagist
  but does not update the changelog.

Third trap, in the same step: **do not use GitHub's "Generate release notes"
button.** `.github/release.yml` categorises by pull-request label and this
repository has had no labelled pull requests, so the generated notes will be
empty or near-empty. Paste the changelog content from step 2.

## 4. Packagist

The four Packagist badges in the README currently 404, because the package has
never been submitted. The first release is when that changes.

1. Submit once at <https://packagist.org/packages/submit> with the GitHub URL.
2. Install the Packagist GitHub App on the repository — or the legacy webhook —
   so subsequent tags sync automatically. Without it, every future release needs
   a manual "Update" click.
3. Confirm the Packagist page shows `php: ^8.3` and both Laravel constraints,
   and that the four README badges render.

## 5. Post-release verification

Not optional, and not the same thing as CI passing. In a scratch directory:

```bash
composer create-project laravel/laravel lcr-smoke
cd lcr-smoke
composer require expertsystemsau/laravel-conditional-requests
php artisan vendor:publish --tag="laravel-conditional-requests-config"
```

Confirm that `1.0.0` resolves, that the provider is auto-discovered with no
manual registration, that the config file publishes, and that a single route
with `->middleware('conditional')` returns an `ETag` and then a `304`. This is
the only step that exercises the package the way a stranger will — through
Packagist, into a real application, with nothing from this repository on disk.

## 6. Branch for patches

```bash
git branch 1.x v1.0.0 && git push origin 1.x
```

Both workflows already trigger on `'*.x'`, which currently matches nothing. This
is the branch that makes that trigger real.
