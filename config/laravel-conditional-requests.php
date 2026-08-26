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
    | It is not only a caching switch. The write path checks this key first, so
    | turning it off also removes every lost-update guard in the application:
    | a "conditional:required" route stops answering 428 for a write that names
    | no version, stops refusing a stale If-Match with 412, and applies the
    | write. Flipping this during an incident quietly reopens the mid-air
    | collision it was never about. "exclude" behaves the same way and is the
    | narrower tool — reach for it on the routes actually giving trouble.
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
    | Last-Modified
    |--------------------------------------------------------------------------
    |
    | Whether model-derived validators publish the record's modification date
    | alongside the ETag, so a client can revalidate a read with
    | If-Modified-Since and guard a write with If-Unmodified-Since.
    |
    | Set this to false to keep the whole family out of the conversation:
    | responses then carry no model-derived Last-Modified, an If-Modified-Since
    | can never produce a 304 off one, and a write offering only
    | If-Unmodified-Since is refused with 412 rather than proceeding unguarded.
    | This key governs the dates this package derives, not every date on the
    | wire: a response whose Last-Modified the application set itself is left
    | alone either way, and Symfony still compares an If-Modified-Since against
    | it and still answers 304. A date is published only once the second holding
    | the change has elapsed, so a record that has just changed carries its ETag
    | alone until that second is over.
    |
    */

    'last_modified' => true,

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

    /*
    |--------------------------------------------------------------------------
    | Lock Wait Timeout
    |--------------------------------------------------------------------------
    |
    | How many seconds a route flagged "conditional:...,lock" will wait for the
    | row lock before giving up with 503 Service Unavailable. Set 0 to leave
    | your server's own setting alone — note that PostgreSQL's lock_timeout
    | defaults to 0, which means wait forever.
    |
    | Applied per request on PostgreSQL (SET LOCAL, transaction-scoped) and on
    | MySQL / MariaDB (SET SESSION, restored afterwards). Other drivers have no
    | equivalent and ignore it; sqlite has no row locks at all.
    |
    | This key is flat rather than nested under a "lock" array on purpose:
    | mergeConfigFrom() merges only top-level keys, so a published config file
    | written before this key existed would read a nested lock.timeout as null
    | instead of picking up the default below.
    |
    */

    'lock_timeout' => 5,

];
