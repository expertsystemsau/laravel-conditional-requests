<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Validators;

/**
 * An HTTP validator: the opaque token identifying one version of a resource.
 */
final readonly class Validator
{
    /**
     * The entity tag, stored bare — no surrounding quotes, no weakness prefix.
     */
    public string $etag;

    public function __construct(string $etag, public bool $weak = false)
    {
        $this->etag = self::normalize($etag);
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
}
