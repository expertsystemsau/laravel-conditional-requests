<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Exceptions;

use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * 412 Precondition Failed — the client named a version of the resource that is
 * no longer current, so the write was refused rather than applied over the top
 * of someone else's.
 *
 * Extends Symfony's own 412, and through it HttpException, so an application
 * renders and customises it through the exception handler it already has and
 * any handler already catching Symfony's type catches this one too.
 */
final class PreconditionFailedException extends PreconditionFailedHttpException
{
    /**
     * The translation key for this exception's default message.
     */
    public const string MESSAGE_KEY = 'laravel-conditional-requests::messages.precondition_failed';
}
