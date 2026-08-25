<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Contracts;

use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface ValidatorStrategy
{
    /**
     * Produce a validator for a rendered response, or null to leave it untouched.
     */
    public function fromResponse(Request $request, Response $response): ?Validator;
}
