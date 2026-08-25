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
    | Any algorithm accepted by hash(). This is a change-detection fingerprint
    | rather than a security primitive, so the non-cryptographic xxh128 is the
    | default for its throughput on large payloads.
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
    | large payload is never hashed just to discover it changed.
    |
    */

    'max_response_bytes' => 1_048_576,

    /*
    |--------------------------------------------------------------------------
    | Eligible Methods
    |--------------------------------------------------------------------------
    |
    | Request methods that take part in the read path.
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
