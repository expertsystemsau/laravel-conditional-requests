<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Exceptions;

use Symfony\Component\HttpKernel\Exception\PreconditionRequiredHttpException;

/**
 * 428 Precondition Required — the route demands that a write state which
 * version it believes it is modifying, and this one did not.
 *
 * This is the status werk365/etagconditionals has no concept of: it returns
 * early when If-Match is absent, so its guard is opt-out and a client goes back
 * to clobbering freely by omitting one header.
 */
final class PreconditionRequiredException extends PreconditionRequiredHttpException
{
    /**
     * The translation key for this exception's default message.
     */
    public const string MESSAGE_KEY = 'laravel-conditional-requests::messages.precondition_required';
}
