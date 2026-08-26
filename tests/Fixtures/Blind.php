<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Tests\Fixtures;

/**
 * A record that exists and can never speak for its own version.
 *
 * conditionalVersionColumns() returning an empty list is documented on the
 * trait as the way to opt a model out of validators entirely, and it produces
 * the state the create guard used to misread: the row is there, the strategy
 * has nothing to compare, and "no validator" is not "no record".
 */
class Blind extends Article
{
    protected $table = 'articles';

    protected function conditionalVersionColumns(): array
    {
        return [];
    }
}
