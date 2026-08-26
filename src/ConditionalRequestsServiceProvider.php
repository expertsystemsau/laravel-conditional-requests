<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests;

use ExpertSystems\ConditionalRequests\Http\Middleware\Conditional;
use ExpertSystems\ConditionalRequests\Locking\LockWait;
use ExpertSystems\ConditionalRequests\Validators\BodyHashStrategy;
use ExpertSystems\ConditionalRequests\Validators\ModelStrategy;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Illuminate\Database\ConcurrencyErrorDetector;
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

        $this->app->bind(LockWait::class, function (): LockWait {
            // Resolved the way the framework's own DetectsConcurrencyErrors
            // resolves it — from the container when an application has bound a
            // detector of its own, from the concrete class otherwise. Nothing
            // in a default install binds the contract, so type-hinting it on
            // LockWait's constructor alone would fail to resolve.
            return new LockWait(
                app()->bound(ConcurrencyErrorDetectorContract::class)
                    ? app(ConcurrencyErrorDetectorContract::class)
                    : new ConcurrencyErrorDetector,
            );
        });
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
            $config = app(Repository::class);

            return new BodyHashStrategy(
                (string) $config->get('laravel-conditional-requests.hash'),
                (bool) $config->get('laravel-conditional-requests.weak'),
            );
        });

        $this->app->make(ConditionalRequests::class)->extend('model', function (): ModelStrategy {
            // Resolved per call for the same reason `body` is — see above.
            $config = app(Repository::class);

            return new ModelStrategy(
                (bool) $config->get('laravel-conditional-requests.weak'),
                (bool) $config->get('laravel-conditional-requests.last_modified'),
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
