<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests;

use ExpertSystems\ConditionalRequests\Console\Commands\ConditionalRequestsCommand;
use Illuminate\Support\ServiceProvider;

class ConditionalRequestsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-conditional-requests.php', 'laravel-conditional-requests');

        $this->app->singleton(ConditionalRequests::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'laravel-conditional-requests');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-conditional-requests.php' => config_path('laravel-conditional-requests.php'),
        ], ['laravel-conditional-requests', 'laravel-conditional-requests-config']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/laravel-conditional-requests'),
        ], ['laravel-conditional-requests', 'laravel-conditional-requests-lang']);

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/laravel-conditional-requests'),
        ], ['laravel-conditional-requests', 'laravel-conditional-requests-assets']);

        $this->commands([
            ConditionalRequestsCommand::class,
        ]);
    }
}
