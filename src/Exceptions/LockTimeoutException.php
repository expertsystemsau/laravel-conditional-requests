<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Exceptions;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/**
 * The row lock could not be taken in time.
 *
 * 503 rather than 409 or 500. Nothing about the request was wrong and nothing
 * failed: another session holds the row and the correct client action is to
 * send the identical request again shortly, which is exactly what 503 with a
 * Retry-After means. 409 would tell the client its request conflicts with the
 * resource state and imply that changing it would help — it would not — and a
 * 500 would report a fault that did not occur.
 *
 * An application that would rather answer something else catches this type in
 * the exception handler it already has; nothing here is package-specific.
 *
 * The constructor puts $message first, unlike the Symfony parent whose first
 * argument is $retryAfter, so that it matches PreconditionFailedException and
 * PreconditionRequiredException and the middleware can construct all three the
 * same way.
 */
final class LockTimeoutException extends ServiceUnavailableHttpException
{
    public const string MESSAGE_KEY = 'laravel-conditional-requests::messages.lock_timeout';

    public function __construct(string $message = '', ?Throwable $previous = null, int|string $retryAfter = 1)
    {
        parent::__construct($retryAfter, $message, $previous);
    }
}
