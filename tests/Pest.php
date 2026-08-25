<?php

declare(strict_types=1);

use ExpertSystems\ConditionalRequests\Contracts\ValidatorStrategy;
use ExpertSystems\ConditionalRequests\Tests\TestCase;
use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

uses(TestCase::class)->in(__DIR__);

/**
 * A strategy that ignores the response entirely and returns a known tag, so a
 * test can prove which strategy the middleware picked from the ETag alone.
 */
function fixedTagStrategy(string $tag): ValidatorStrategy
{
    return new class($tag) implements ValidatorStrategy
    {
        public function __construct(private readonly string $tag) {}

        public function fromResponse(Request $request, Response $response): ?Validator
        {
            return new Validator($this->tag);
        }
    };
}
