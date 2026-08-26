# Placement and ordering

## The rule

<!-- Task 5 -->

## Route and group placement

<!-- Task 5 -->

## Kernel-global placement

<!-- Task 5 -->

## What must run before `conditional`

> [!IMPORTANT]
> `conditional` must run **after** route model binding. Inside the `api` or `web` middleware group that is already true, since `SubstituteBindings` belongs to both. On a route that has not had its bindings substituted yet — kernel-global placement, or a hand-written middleware list that puts `conditional` first — the strategy finds no record before the controller runs and the request quietly takes the ordinary path: the `ETag` is still attached on the way out, but the controller runs and nothing is saved.

## What must run inside `conditional`

A validator identifies one specific set of bytes, and this middleware computes it from the bytes it can see, where it sits. Anything that rewrites the body *after* that — an HTML minifier, a CSP-nonce injector, a CSRF token refresher, a debug bar, a response filter of any kind — leaves the tag describing bytes the client never received. Global middleware always run outside route middleware, so a global rewriter is always in that position; a route middleware declared before `conditional` is too.

```http
ETag: "d5bdba419a6ee56156a6005f54f6b73f"    nonce="ee6508f9fd94"
ETag: "d5bdba419a6ee56156a6005f54f6b73f"    nonce="d2e555ab4bb1"
ETag: "d5bdba419a6ee56156a6005f54f6b73f"    nonce="f73849aac055"
```

One strong tag, three different bodies. A client revalidating with it is told `304` every time, so it keeps the first nonce forever while the server goes on minting new ones — a CSP nonce frozen, or a stale CSRF token in every form on the page.

Declare anything that rewrites the body **after** `conditional`, so the bytes are final before they are hashed. Under `model` the ordering does not help, because that tag never described the bytes to begin with: a per-response nonce or token is exactly what [the scoping section](#what-the-tag-is-scoped-to) says has to be folded into the tag by hand, or kept off a conditional route.

## Terminable middleware

<!-- Task 5 -->

## Reverse proxies

A validator identifies one specific set of bytes, and a reverse proxy that compresses your response changes those bytes. nginx drops the `ETag` outright when it gzips; Apache appends `-gzip` to it. Either way the tag the client holds is not the tag this middleware computed, so `If-None-Match` stops matching and you never see a `304`.

If `304`s work in local development and never in production, check the proxy first — `curl -sI -H 'Accept-Encoding: gzip' <url>` against the proxy and against the app directly will show the difference immediately.
