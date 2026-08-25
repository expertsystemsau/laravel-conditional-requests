<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Concerns;

use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The default ProvidesConditionalValidator implementation for an Eloquent model.
 *
 * The tag fingerprints identity and version together: the table the record
 * lives in, its key, and either an explicit version column or the raw
 * updated_at value at whatever precision the column stores. Identity is in
 * there so that two records with the same key and the same timestamp, in
 * different tables, cannot be mistaken for one another.
 *
 * Model-derived validators are strong (design §4). RFC 9110 §13.1.1 requires
 * strong comparison for If-Match, so a weak tag here would reject every
 * guarded write on the v0.3 write path.
 *
 * @phpstan-require-extends Model
 */
trait HasConditionalValidator
{
    public function conditionalValidator(Request $request): ?Validator
    {
        $key = $this->getKey();
        $version = $this->conditionalVersion();

        // A record with no version has nothing for a client to agree on, and
        // inventing one hands out a tag that can never match again.
        if ($version === null || (! is_string($key) && ! is_int($key))) {
            return null;
        }

        return new Validator(hash('xxh128', implode("\0", [
            $this->getTable(),
            (string) $key,
            $version,
        ])));
    }

    /**
     * The columns consulted for a version token, in order of preference.
     *
     * Override to point at a different column, or to return an empty list on a
     * model that should never produce a validator.
     *
     * @return list<string>
     */
    protected function conditionalVersionColumns(): array
    {
        $columns = ['version'];

        if ($this->usesTimestamps() && is_string($column = $this->getUpdatedAtColumn())) {
            $columns[] = $column;
        }

        return $columns;
    }

    /**
     * The first usable version token on the record.
     *
     * Raw attributes are read rather than cast ones: the raw value is exactly
     * what the column holds, at exactly the precision it holds it, and it never
     * shifts because an application added a cast or an accessor for display.
     */
    private function conditionalVersion(): ?string
    {
        $attributes = $this->getAttributes();

        foreach ($this->conditionalVersionColumns() as $column) {
            $value = $attributes[$column] ?? null;

            if (is_int($value)) {
                return (string) $value;
            }

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
