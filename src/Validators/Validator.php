<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Validators;

use InvalidArgumentException;

/**
 * An HTTP validator: the opaque token identifying one version of a resource.
 */
final readonly class Validator
{
    /**
     * The entity tag, stored bare — no surrounding quotes, no weakness prefix.
     */
    public string $etag;

    /**
     * @throws InvalidArgumentException when the tag cannot be rendered as a header
     */
    public function __construct(string $etag, public bool $weak = false)
    {
        $this->etag = self::validate(self::normalize($etag));
    }

    /**
     * The tag as it appears on the wire.
     */
    public function header(): string
    {
        return ($this->weak ? 'W/' : '').'"'.$this->etag.'"';
    }

    /**
     * Accept a bare, quoted, or weak-prefixed tag and reduce it to the bare form.
     */
    private static function normalize(string $etag): string
    {
        $etag = trim($etag);

        if (str_starts_with($etag, 'W/')) {
            $etag = substr($etag, 2);
        }

        return trim($etag, '"');
    }

    /**
     * Reject a normalised tag that cannot appear inside a quoted entity tag.
     *
     * RFC 9110 §8.8.3 defines `etagc` as `%x21 / %x23-7E / obs-text`. This is a
     * deliberately minimal guard on the three characters that actually break
     * the header — a double quote, a comma, and a control character — plus the
     * empty tag. It is not a full `obs-text` check; strategies are trusted
     * beyond that.
     *
     * A comma is legal `etagc` and still has to go. `If-Match` and
     * `If-None-Match` carry a `#entity-tag` list, so a tag containing one
     * splits into two malformed members the moment a client echoes it back,
     * neither of which can ever match — a permanent 412 on that resource. The
     * package's own strategies emit hex and cannot reach it; a custom
     * ValidatorStrategy can, and this is where it finds out.
     *
     * @throws InvalidArgumentException
     */
    private static function validate(string $etag): string
    {
        if ($etag === '') {
            throw new InvalidArgumentException('An entity tag cannot be empty.');
        }

        if (str_contains($etag, '"')) {
            throw new InvalidArgumentException(
                "Entity tag [{$etag}] contains a double quote, which RFC 9110 does not permit inside one.",
            );
        }

        if (str_contains($etag, ',')) {
            throw new InvalidArgumentException(
                "Entity tag [{$etag}] contains a comma, which would split it across two members of an If-Match list.",
            );
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $etag) === 1) {
            throw new InvalidArgumentException(
                'An entity tag cannot contain control characters.',
            );
        }

        return $etag;
    }
}
