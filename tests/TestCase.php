<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Tests;

use ExpertSystems\ConditionalRequests\ConditionalRequestsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ConditionalRequestsServiceProvider::class,
        ];
    }
}
