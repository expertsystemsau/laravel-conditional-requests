<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \ExpertSystems\ConditionalRequests\ConditionalRequests
 */
class ConditionalRequests extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ExpertSystems\ConditionalRequests\ConditionalRequests::class;
    }
}
