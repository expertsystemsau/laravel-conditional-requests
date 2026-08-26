<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch. When false the middleware becomes a pass-through, which
    | is useful for isolating behaviour while debugging a caching problem.
    |
    */

    'enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Default Validator Strategy
    |--------------------------------------------------------------------------
    |
    | Which registered strategy produces validators when a route does not name
    | one itself. "body" hashes the rendered response and works anywhere.
    |
    */

    'strategy' => 'body',

    /*
    |--------------------------------------------------------------------------
    | Hash Algorithm
    |--------------------------------------------------------------------------
    |
    | Any algorithm accepted by hash(), governing the "body" strategy — the only
    | one that hashes attacker-reachable input. This is a change-detection
    | fingerprint rather than a security primitive, so the non-cryptographic
    | xxh128 is the default for its throughput on large payloads. If response
    | bodies carry attacker-influenced content and serving a stale
    | representation matters, choose a cryptographic algorithm instead: xxh128
    | offers no collision resistance against a chosen input, so a crafted body
    | can be made to collide with an earlier one and suppress the refresh.
    |
    | Model-derived tags do not read this key and do not need to. They hash a
    | database name, a table prefix, a table, a primary key, and a version
    | column — none of which a client chooses — so there is no input to craft a
    | collision out of.
    |
    */

    'hash' => 'xxh128',

    /*
    |--------------------------------------------------------------------------
    | Weak Validators
    |--------------------------------------------------------------------------
    |
    | Emit weak ETags (W/"...") which compare as equal when content is merely
    | semantically equivalent. Leave false unless you know you want it: weak
    | validators cannot satisfy an If-Match precondition on the write path.
    |
    */

    'weak' => false,

    /*
    |--------------------------------------------------------------------------
    | Maximum Response Size
    |--------------------------------------------------------------------------
    |
    | Responses larger than this many bytes are passed through untouched, so a
    | large payload is never hashed just to discover it changed. Set this to 0
    | (or any non-positive value) to remove the ceiling entirely — 0 means
    | unlimited, not "never tag".
    |
    */

    'max_response_bytes' => 1_048_576,

    /*
    |--------------------------------------------------------------------------
    | Eligible Methods
    |--------------------------------------------------------------------------
    |
    | Request methods that take part in the read path. This does not gate the
    | write path: every unsafe method — POST, PUT, PATCH, DELETE — takes the
    | write path when a route carries the middleware, whether or not it is
    | actually guarded there — with the default "body" strategy and no
    | "required" flag, that write path is a pure pass-through. Adding a method
    | here does not start attaching validators to its responses. Use "exclude"
    | to opt a route out of both paths at once.
    |
    */

    'methods' => ['GET', 'HEAD'],

    /*
    |--------------------------------------------------------------------------
    | Exclusions
    |--------------------------------------------------------------------------
    |
    | Route names or URI patterns that never receive a validator. Both accept
    | the usual "*" wildcard, e.g. "admin.*" or "internal/*".
    |
    */

    'exclude' => [],

];
