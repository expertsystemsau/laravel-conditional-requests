<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Validators;

use ExpertSystems\ConditionalRequests\Contracts\ValidatorStrategy;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Derives a validator by hashing the rendered response body.
 *
 * Works on any route with no setup, at the cost of running the controller
 * before the validator is known — this strategy saves bandwidth, not compute.
 */
final readonly class BodyHashStrategy implements ValidatorStrategy
{
    /**
     * @throws InvalidArgumentException when hash() does not support the algorithm
     */
    public function __construct(
        private string $algorithm = 'xxh128',
        private bool $weak = false,
    ) {
        if (! in_array($algorithm, hash_algos(), true)) {
            throw new InvalidArgumentException(
                "Hash algorithm [{$algorithm}] is not supported. Check the laravel-conditional-requests.hash config value.",
            );
        }
    }

    public function fromResponse(Request $request, Response $response): ?Validator
    {
        $content = $response->getContent();

        if ($content === false || $content === '') {
            return null;
        }

        return new Validator(hash($this->algorithm, $content), $this->weak);
    }
}
