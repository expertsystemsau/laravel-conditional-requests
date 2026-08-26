<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Concerns;

use DateTimeInterface;
use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The default ProvidesConditionalValidator implementation for an Eloquent model.
 *
 * The tag fingerprints identity and version together: where the record lives —
 * the connection's database name, the connection's table prefix, and the table
 * — its key, and either an explicit version column or the raw updated_at value
 * at whatever precision the column stores. Identity is in there so that two
 * records with the same key and the same version, in different tables or in
 * different tenants' storage, cannot be mistaken for one another.
 *
 * Identity is only ever as specific as the connection is. Database-per-tenant
 * and prefix-per-tenant separate, because the database name and the prefix are
 * both in the fingerprint. Single-database row-level tenancy does not: every
 * tenant shares one database, one prefix, and one table, so tenant A's row 1 at
 * version 1 and tenant B's still produce the same tag. A deployment on that
 * model must override conditionalValidator() to fold the tenant in — see the
 * README's note on the scope of a tag.
 *
 * The version is trusted absolutely: whatever it holds is the entire answer to
 * "has this record changed since". A pre-existing `version` column that means
 * something else, or that only some write paths move, freezes the tag and keeps
 * answering 304 against content that has changed. See the README warning.
 *
 * Model-derived validators are strong (design §4). RFC 9110 §13.1.1 requires
 * strong comparison for If-Match, so a weak tag here would reject every
 * guarded write on the v0.3 write path.
 *
 * conditionalValidator() receives $request (see ProvidesConditionalValidator),
 * but this trait does not thread it any further: conditionalVersionColumns(),
 * the extension point below, only names columns and has no access to the
 * request. An application that needs to fold request-dependent state —
 * content negotiation, sparse fieldsets, includes, the viewer, the tenant —
 * into the tag has to override conditionalValidator() itself rather than
 * conditionalVersionColumns().
 *
 * The validator also carries the record's modification date, subject to one
 * rule: an instant whose second has not finished yet is not published at all.
 * See conditionalLastModified() for why.
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
        // inventing one hands out a tag that can never match again. The key is
        // read through getKey(), which returns the *cast* value, while the
        // version below is read raw — so a model with an object or enum cast on
        // its primary key produces no validator at all. That fails safe, but it
        // fails silently: the route simply never gets an ETag.
        if ($version === null || (! is_string($key) && ! is_int($key))) {
            return null;
        }

        return new Validator(hash('xxh128', implode("\0", [
            ...$this->conditionalLocation(),
            (string) $key,
            $version,
        ])), lastModified: $this->conditionalLastModified());
    }

    /**
     * Where this record lives, as fingerprint components.
     *
     * getTable() is the bare table name — no database, no prefix — so a tag
     * built from it alone is identical for the same key and version in every
     * tenant of a database-per-tenant or prefix-per-tenant deployment. Both are
     * read off the connection, which holds them as configuration: no query, and
     * no connection is opened to answer.
     *
     * @return list<string>
     */
    private function conditionalLocation(): array
    {
        $connection = $this->getConnection();

        // A connection configured without a database name — some drivers do not
        // need one — or without a prefix contributes an empty component rather
        // than dropping out of the fingerprint, so the position of every later
        // component holds. Both are @return string docblocks over untyped
        // properties that the same ConnectionFactory route can leave null, so
        // both are cast: without that this method's list<string> is untrue.
        return [
            (string) $connection->getDatabaseName(),
            (string) $connection->getTablePrefix(),
            $this->getTable(),
        ];
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
     * The column holding the record's modification date, or null when it has none.
     *
     * Override to point at a different column, or to return null on a model
     * that should publish no Last-Modified. Returning null does not affect the
     * entity tag, which keeps validating the record either way.
     */
    protected function conditionalLastModifiedColumn(): ?string
    {
        if (! $this->usesTimestamps()) {
            return null;
        }

        $column = $this->getUpdatedAtColumn();

        return is_string($column) ? $column : null;
    }

    /**
     * The record's modification instant, or null when it has none this response
     * can safely publish.
     *
     * The comparison at the end is the whole Last-Modified problem in one line.
     * An HTTP-date has one-second resolution (RFC 9110 §5.6.7), so a record
     * modified at 12:00:00.700 can only be advertised as 12:00:00 — and if it
     * is modified again at 12:00:00.900, a client echoing 12:00:00 back would
     * be told 304 while holding a stale representation. The same is true of a
     * whole-second column, where both writes literally store 12:00:00: it is
     * the wire format's granularity, not the column's.
     *
     * RFC 9110 §8.8.2.2 permits a date validator to be treated as strong only
     * when the server "reliably knows that the associated representation did
     * not change twice during the second covered by the presented validator".
     * That cannot be known about a second that is still running, and is always
     * true of one that has finished. So a date is published only once its
     * second has fully elapsed, which also suppresses a future-dated value
     * from a skewed clock — the same comparison, the other side of it.
     *
     * The cost is bounded: for the remainder of the second in which a record
     * changed, its responses carry an entity tag and no date. The tag is
     * derived from the raw column at whatever precision it stores, so it keeps
     * validating during exactly the window in which the date cannot.
     *
     * The clock is the model's own — freshTimestamp() is what Eloquent used to
     * write the column in the first place, it honours Carbon::setTestNow(), and
     * an application that overrides it gets one clock rather than two.
     */
    private function conditionalLastModified(): ?DateTimeInterface
    {
        $column = $this->conditionalLastModifiedColumn();

        if ($column === null || ! array_key_exists($column, $this->getAttributes())) {
            return null;
        }

        try {
            $modified = $this->getAttribute($column);
        } catch (InvalidArgumentException) {
            // A stored value the model's date format cannot parse. A validator
            // is an optimisation and is never the reason a request fails —
            // the same degradation Conditional::stringList() applies to a
            // malformed config value.
            return null;
        }

        if (! $modified instanceof DateTimeInterface) {
            return null;
        }

        return $modified->getTimestamp() >= $this->freshTimestamp()->getTimestamp() ? null : $modified;
    }

    /**
     * The first usable version token on the record.
     *
     * Raw attributes are read rather than cast ones: for a primitive cast the
     * raw value is exactly what the column holds, at exactly the precision it
     * holds it, and does not shift because an application added a cast or an
     * accessor for display. That does not hold for an enum- or class-castable
     * column: Model::getAttributes() calls mergeAttributesFromCachedCasts(),
     * which writes the cast's serialized form back into the raw attribute
     * array once that accessor has been touched earlier in the same request —
     * so the value read here can still shift mid-request for those cast types.
     * Point conditionalVersionColumns() at one only if that is acceptable.
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
