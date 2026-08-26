# Contribution Guide

Thank you for considering contributing to Laravel Conditional Requests! Please review the following guidelines before submitting a pull request.

For significant changes, please open an issue first so we can discuss the approach.

## Process

1. Fork the project
2. Create a new branch
3. Code, test, commit, and push
4. Open a pull request detailing your changes

Maintainers cutting a release: see [RELEASING.md](RELEASING.md).

## Guidelines

- Ensure the coding style passes by running `composer lint`.
- Send a coherent commit history, making sure each commit in your pull request is meaningful.
- You may need to [rebase](https://git-scm.com/book/en/v2/Git-Branching-Rebasing) to avoid merge conflicts.
- Please remember that we follow [SemVer](http://semver.org/).
- The public API is frozen and enforced by `tests/Feature/PublicApiTest.php`. If a change makes that test fail, it is an API change: update the frozen list and [`docs/api.md`](../docs/api.md) in the same commit, and say in the changelog which of `major`, `minor` or `patch` it is.
- The first change that breaks the frozen surface creates `UPGRADING.md` at the repository root, with one section per breaking change and the migration for each. There is no such file yet because there has been nothing to upgrade from.

## Setup

Clone your fork, then install the dev dependencies:

```bash
composer install
```

## Lint

Lint your code:

```bash
composer lint
```

## Tests

Run all tests:

```bash
composer test
```
