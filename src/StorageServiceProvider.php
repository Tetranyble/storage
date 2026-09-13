<?php

namespace Tetranyble\Storage;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Tetranyble\Storage\Console\CleanupDirectUploadsCommand;
use Tetranyble\Storage\Console\CleanupStorageOrphansCommand;
use Tetranyble\Storage\Console\ProcessPendingMediaCommand;
use Tetranyble\Storage\Console\ReconcileStorageUsageCommand;
use Tetranyble\Storage\Console\StorageHealthCommand;
use Tetranyble\Storage\Console\StorageRetentionCommand;
use Tetranyble\Storage\Http\Middleware\HandleStorageExceptions;
use Tetranyble\Storage\Infrastructure\Laravel\StorageBindings;
use Tetranyble\Storage\Infrastructure\Laravel\StorageConfigurationValidator;
use Tetranyble\Storage\Infrastructure\Laravel\StorageRateLimiters;

final class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tetranyble-storage.php', 'tetranyble-storage');
        $this->app->singleton(StorageConfigurationValidator::class);
        $this->app->make(StorageConfigurationValidator::class)->validate();
        StorageBindings::register($this->app);
    }

    public function boot(): void
    {
        $this->registerExceptionRendering();
        $this->app->make(StorageRateLimiters::class)->register();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        if ($this->shouldLoadActivityMigrations()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations/activities');
        }
        if ((bool) config('tetranyble-storage.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/storage.php');
        }

        $this->publishes([
            __DIR__.'/../config/tetranyble-storage.php' => config_path('tetranyble-storage.php'),
        ], 'tetranyble-storage-config');
        $this->publishes($this->migrationPublishPaths(__DIR__.'/../database/migrations'), 'tetranyble-storage-migrations');
        $this->publishes(
            $this->migrationPublishPaths(__DIR__.'/../database/migrations/activities'),
            'tetranyble-storage-activity-migrations',
        );
        $this->publishes([
            __DIR__.'/../routes/storage.php' => base_path('routes/storage.php'),
        ], 'tetranyble-storage-routes');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanupStorageOrphansCommand::class,
                ReconcileStorageUsageCommand::class,
                ProcessPendingMediaCommand::class,
                CleanupDirectUploadsCommand::class,
                StorageHealthCommand::class,
                StorageRetentionCommand::class,
            ]);
        }
    }

    private function shouldLoadActivityMigrations(): bool
    {
        return (bool) config(
            'tetranyble-storage.activities.load_migrations',
            config('tetranyble-storage.activities.enabled', false),
        );
    }

    private function registerExceptionRendering(): void
    {
        if (! $this->app->bound(ExceptionHandler::class)) {
            return;
        }

        $handler = $this->app->make(ExceptionHandler::class);
        if (! method_exists($handler, 'renderable')) {
            return;
        }

        $handler->renderable(function (\Throwable $exception, Request $request) {
            return $this->app->make(HandleStorageExceptions::class)->render($request, $exception);
        });
    }

    private function migrationPublishPaths(string $directory): array
    {
        $migrations = glob($directory.'/*_*.php') ?: [];

        return collect($migrations)
            ->mapWithKeys(fn (string $path) => [$path => database_path('migrations/'.basename($path))])
            ->all();
    }
}
