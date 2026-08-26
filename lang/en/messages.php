<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Precondition Messages
    |--------------------------------------------------------------------------
    |
    | The bodies of the two refusals the write path issues. Publish this file
    | with the "laravel-conditional-requests-lang" tag to reword them, or catch
    | the exceptions in your own handler and render whatever you like.
    |
    */

    'precondition_failed' => 'The version you are modifying is no longer current. Fetch the resource again, reapply your changes, and retry with the If-Match value from the new response.',

    'precondition_required' => 'This request must be conditional. Send an If-Match header carrying the entity tag of the version you are modifying, or If-None-Match: * to create a resource only if it does not already exist.',

    /*
    |--------------------------------------------------------------------------
    | Lock Timeout Message
    |--------------------------------------------------------------------------
    |
    | The body of the 503 a guarded write receives when the row it needs is
    | already locked by another request and the wait ran out.
    |
    */

    'lock_timeout' => 'This resource is being modified by another request. Nothing was changed. Please retry in a moment.',

];
