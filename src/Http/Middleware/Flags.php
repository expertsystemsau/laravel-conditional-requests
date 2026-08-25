<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Http\Middleware;

/**
 * The parsed middleware parameter list.
 *
 * Design §5.2 makes flags order-independent and reserves words that name
 * behaviour rather than a strategy. `required` (v0.3) and `lock` (v0.5) are
 * reserved here, ahead of the releases that implement them, so that
 * `conditional:required` parses today instead of asking the registry for a
 * strategy of that name and throwing. Adding a reserved word later means
 * extending the chain in parse() and adding a flag to this object.
 */
final readonly class Flags
{
    private function __construct(
        public ?string $strategy,
        public bool $required,
        public bool $lock,
    ) {}

    /**
     * @param  list<string>  $flags
     */
    public static function parse(array $flags): self
    {
        $strategy = null;
        $required = false;
        $lock = false;

        foreach ($flags as $flag) {
            $flag = trim($flag);

            if ($flag === '') {
                continue;
            }

            if ($flag === 'required') {
                $required = true;
            } elseif ($flag === 'lock') {
                $lock = true;
            } elseif ($strategy === null) {
                $strategy = $flag;
            }
        }

        return new self($strategy, $required, $lock);
    }

    /**
     * The strategy to use: an explicit flag first, then the reserved-word
     * implication, then the configured default.
     *
     * §5.2 has `required` and `lock` imply `model`, because the write path must
     * know the current validator before the controller runs and a body hash
     * cannot supply one. An explicitly named strategy still wins — pairing
     * `required` with a strategy that cannot serve it becomes a boot-time error
     * in v0.3 rather than a silent override here.
     */
    public function strategyOr(string $default): string
    {
        if ($this->strategy !== null) {
            return $this->strategy;
        }

        if ($this->required || $this->lock) {
            return 'model';
        }

        return $default;
    }
}
