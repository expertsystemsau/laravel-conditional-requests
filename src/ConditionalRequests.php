<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests;

use Closure;
use ExpertSystems\ConditionalRequests\Contracts\ValidatorStrategy;
use InvalidArgumentException;

/**
 * Registry of the validator strategies available to the middleware.
 */
final class ConditionalRequests
{
    /**
     * @var array<string, Closure(): ValidatorStrategy>
     */
    private array $strategies = [];

    /**
     * Register a strategy under a name usable as a middleware flag.
     *
     * @param  Closure(): ValidatorStrategy  $resolver
     */
    public function extend(string $name, Closure $resolver): void
    {
        $this->strategies[$name] = $resolver;
    }

    /**
     * Resolve a registered strategy.
     *
     * @throws InvalidArgumentException when no strategy is registered under the name
     */
    public function strategy(string $name): ValidatorStrategy
    {
        $resolver = $this->strategies[$name] ?? null;

        if (! $resolver instanceof Closure) {
            $registered = array_keys($this->strategies);

            throw new InvalidArgumentException(sprintf(
                'Conditional request strategy [%s] is not registered. Registered: %s',
                $name,
                $registered === [] ? 'none' : implode(', ', $registered),
            ));
        }

        return $resolver();
    }
}
