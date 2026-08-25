<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Tests;

use ExpertSystems\ConditionalRequests\ConditionalRequestsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ConditionalRequestsServiceProvider::class,
        ];
    }

    /**
     * Testbench's default connection is a sqlite file the skeleton never
     * creates, so pin an in-memory database the suite can always open.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /**
     * Fixture tables, rebuilt per test — an in-memory database starts empty
     * with every application instance.
     */
    protected function defineDatabaseMigrations(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->unsignedInteger('version')->nullable();
            $table->timestamps();
        });

        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->string('body');
            $table->timestamps();
        });
    }
}
