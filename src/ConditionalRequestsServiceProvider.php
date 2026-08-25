<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests;

use ExpertSystems\ConditionalRequests\Http\Middleware\Conditional;
use ExpertSystems\ConditionalRequests\Validators\BodyHashStrategy;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Router;
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
        $this->app->make(ConditionalRequests::class)->extend('body', function (): BodyHashStrategy {
            // Resolved from the active container per call, not captured at boot.
            // Under Octane an app that lists `config` in `octane.flush` would
            // otherwise leave this closure reading a stale Repository while the
            // middleware reads the sandbox's own — split-brain configuration.
            $config = Container::getInstance()->make(Repository::class);

            return new BodyHashStrategy(
                (string) $config->get('laravel-conditional-requests.hash'),
                (bool) $config->get('laravel-conditional-requests.weak'),
            );
        });

        $this->app->make(Router::class)->aliasMiddleware('conditional', Conditional::class);

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
    }
}
