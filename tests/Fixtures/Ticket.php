<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Tests\Fixtures;

use ExpertSystems\ConditionalRequests\Contracts\ProvidesConditionalValidator;
use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Http\Request;

/**
 * A resource that knows its own version and has no row behind it — design
 * §5.5's "non-database resource", the case where `lock` cannot mean anything.
 */
final readonly class Ticket implements ProvidesConditionalValidator
{
    public function __construct(private string $version = '1') {}

    public function conditionalValidator(Request $request): ?Validator
    {
        return new Validator('ticket-'.$this->version);
    }
}
