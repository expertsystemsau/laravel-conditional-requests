<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Exceptions\PreconditionFailedException;
use ExpertSystems\ConditionalRequests\Exceptions\PreconditionRequiredException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionRequiredHttpException;

it('reports 412 for a failed precondition', function (): void {
    expect((new PreconditionFailedException)->getStatusCode())->toBe(412);
});

it('reports 428 for a required precondition', function (): void {
    expect((new PreconditionRequiredException)->getStatusCode())->toBe(428);
});

it('renders through the applications existing handler', function (): void {
    // An HttpException is what Laravel's handler already knows how to render,
    // which is why the package needs no rendering hook of its own.
    expect(new PreconditionFailedException)->toBeInstanceOf(HttpException::class)
        ->and(new PreconditionRequiredException)->toBeInstanceOf(HttpException::class);
});

it('is catchable as the symfony exception for the same status', function (): void {
    expect(new PreconditionFailedException)->toBeInstanceOf(PreconditionFailedHttpException::class)
        ->and(new PreconditionRequiredException)->toBeInstanceOf(PreconditionRequiredHttpException::class);
});

it('carries the message it is given', function (): void {
    expect((new PreconditionFailedException('stale'))->getMessage())->toBe('stale')
        ->and((new PreconditionRequiredException('missing'))->getMessage())->toBe('missing');
});

it('names a translation key that resolves to real copy', function (): void {
    expect(trans(PreconditionFailedException::MESSAGE_KEY))
        ->not->toBe(PreconditionFailedException::MESSAGE_KEY)
        ->and(trans(PreconditionRequiredException::MESSAGE_KEY))
        ->not->toBe(PreconditionRequiredException::MESSAGE_KEY);
});

it('explains what the client should do about a 412', function (): void {
    expect(trans(PreconditionFailedException::MESSAGE_KEY))->toContain('If-Match');
});

it('explains what the client should do about a 428', function (): void {
    expect(trans(PreconditionRequiredException::MESSAGE_KEY))->toContain('If-Match');
});

it('no longer ships an empty message file', function (): void {
    expect(require __DIR__.'/../../lang/en/messages.php')->not->toBe([]);
});
