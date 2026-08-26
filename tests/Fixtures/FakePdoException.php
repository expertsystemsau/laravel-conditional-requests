<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Tests\Fixtures;

use PDOException;

/**
 * A PDOException carrying a SQLSTATE string.
 *
 * PDOException::$code is protected and Exception's constructor types $code as
 * an int, so the only place a SQLSTATE like "55P03" can be written is inside a
 * subclass. Real ones are built by the PDO extension; this is how a test builds
 * one.
 */
final class FakePdoException extends PDOException
{
    public function __construct(string $message, string $sqlState)
    {
        parent::__construct($message);

        $this->code = $sqlState;
    }
}
