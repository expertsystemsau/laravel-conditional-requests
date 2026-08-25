<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Validators;

use ExpertSystems\ConditionalRequests\Contracts\ValidatorStrategy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Derives a validator by hashing the rendered response body.
 *
 * Works on any route with no setup, at the cost of running the controller
 * before the validator is known — this strategy saves bandwidth, not compute.
 */
final readonly class BodyHashStrategy implements ValidatorStrategy
{
    public function __construct(
        private string $algorithm = 'xxh128',
        private bool $weak = false,
    ) {}

    public function fromResponse(Request $request, Response $response): ?Validator
    {
        $content = $response->getContent();

        if ($content === false || $content === '') {
            return null;
        }

        return new Validator(hash($this->algorithm, $content), $this->weak);
    }
}
