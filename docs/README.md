# Documentation

- [Conditional reads](reads.md) — attaching validators, the two built-in strategies, the pre-controller short-circuit, `Last-Modified`, and writing your own strategy.
- [Conditional writes](writes.md) — `If-Match`, `412`, `428`, the create guard, `If-Unmodified-Since`, and `lock` mode.
- [Placement and ordering](placement.md) — where `conditional` goes in the middleware stack, and what changes if it goes somewhere else.
- [Configuration](configuration.md) — every key, its default, and what it governs.
- [Hazards](hazards.md) — the things that can bite you, what exposes you to each, and what to do. **Read the scan table before you deploy.**
- [Migrating from `werk365/etagconditionals`](migrating-from-werk365.md) — the mapping, the behaviour changes, and an honest account of when not to bother.
- [Public API and stability](api.md) — what semver covers and what may change without notice.

Project history, not documentation: [`design/`](design) holds the approved design, [`plans/`](plans) the per-phase implementation plans.
