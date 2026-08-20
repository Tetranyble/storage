<?php

namespace Tetranyble\Storage;

use Tetranyble\Storage\Contracts\ActivityFeed;
use Tetranyble\Storage\Contracts\ActivityLogger;
use Tetranyble\Storage\Contracts\ResumableUploadManager;
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Contracts\Workspace;
use Tetranyble\Storage\Contracts\StorageTransferAuthorizer;
use Tetranyble\Storage\Contracts\RemoteMediaImporter;
use Tetranyble\Storage\Contracts\RemoteUrlValidator;
use Tetranyble\Storage\Domain\Activity\DatabaseActivityFeed;
use Tetranyble\Storage\Domain\Activity\DatabaseActivityLogger;
use Tetranyble\Storage\Domain\Activity\NullActivityFeed;
use Tetranyble\Storage\Domain\Activity\NullActivityLogger;
use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\Contracts\MediaUploader;
use Tetranyble\Storage\Domain\FileSystem\FileSystem;
use Tetranyble\Storage\Domain\FileSystem\MediaService;
use Tetranyble\Storage\Domain\FileSystem\ResumableUploadService;
use Tetranyble\Storage\Domain\FileSystem\SafeRemoteUrlValidator;
use Tetranyble\Storage\Domain\CloudDrive\ConnectedDriveService;
use Tetranyble\Storage\Domain\CloudDrive\DownloadService;
use Tetranyble\Storage\Domain\CloudDrive\OAuthService;
use Tetranyble\Storage\Domain\FileSystem\StorageService;
use Tetranyble\Storage\Domain\Media\AccessControlService;
use Tetranyble\Storage\Domain\Media\AccessControlTransferAuthorizer;
use Tetranyble\Storage\Domain\Media\MediaStorageTransferService;
use Tetranyble\Storage\Domain\Media\CommentService;
use Tetranyble\Storage\Domain\Media\MediaPostProcessor;
use Tetranyble\Storage\Domain\Media\MediaShareService;
use Tetranyble\Storage\Domain\Media\MediaVersioningService;
use Tetranyble\Storage\Domain\Media\WorkspaceFileManagerService;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/storage.php', 'storage');
        $this->mergeConfigFrom(__DIR__.'/../config/storage.php', 'tetranyble-storage');
        $this->synchronizeConfigKeys();

        $this->app->bind(FileSystemContract::class, FileSystem::class);
        $this->app->bind(MediaUploader::class, MediaService::class);
        if (! $this->app->bound(RemoteMediaImporter::class)) {
            $this->app->bind(RemoteMediaImporter::class, MediaService::class);
        }
        if (! $this->app->bound(RemoteUrlValidator::class)) {
            $this->app->bind(RemoteUrlValidator::class, SafeRemoteUrlValidator::class);
        }
        $this->app->bind(ResumableUploadManager::class, ResumableUploadService::class);
        if (! $this->app->bound(ActivityLogger::class)) {
            $this->app->bind(ActivityLogger::class, function ($app) {
                $logger = (bool) $app['config']->get('tetranyble-storage.activities.enabled', false)
                    ? DatabaseActivityLogger::class
                    : NullActivityLogger::class;

                return $app->make($logger);
            });
        }
        if (! $this->app->bound(ActivityFeed::class)) {
            $this->app->bind(ActivityFeed::class, function ($app) {
                $feed = (bool) $app['config']->get('tetranyble-storage.activities.enabled', false)
                    ? DatabaseActivityFeed::class
                    : NullActivityFeed::class;

                return $app->make($feed);
            });
        }
        if (! $this->app->bound(ResourceAccessControl::class)) {
            $this->app->bind(ResourceAccessControl::class, AccessControlService::class);
        }
        if (! $this->app->bound(Workspace::class)) {
            $this->app->bind(Workspace::class, function ($app) {
                $resolver = $app['config']->get('tetranyble-storage.workspace.resolver');
                if (! is_string($resolver) || ! is_a($resolver, Workspace::class, true)) {
                    throw new RuntimeException('The configured storage workspace resolver must implement '.Workspace::class.'.');
                }

                return $app->make($resolver);
            });
        }
        if (! $this->app->bound(StorageTransferAuthorizer::class)) {
            $this->app->bind(StorageTransferAuthorizer::class, function ($app) {
                $authorizer = $app['config']->get(
                    'tetranyble-storage.transfer.authorizer',
                    AccessControlTransferAuthorizer::class,
                );
                if (! is_string($authorizer) || ! is_a($authorizer, StorageTransferAuthorizer::class, true)) {
                    throw new RuntimeException('The storage transfer authorizer must implement '.StorageTransferAuthorizer::class.'.');
                }

                return $app->make($authorizer);
            });
        }

        $this->app->bind(CommentService::class, fn ($app) => new CommentService(
            $app->make(ResourceAccessControl::class),
        ));

        $this->app->bind(MediaPostProcessor::class, fn ($app) => new MediaPostProcessor(
            $app->make(FileSystemContract::class),
        ));

        $this->app->bind(MediaVersioningService::class, fn ($app) => new MediaVersioningService(
            $app->make(FileSystemContract::class),
            $app->make(StorageService::class),
            $app->make(ActivityLogger::class),
            $app->make(ActivityFeed::class),
        ));

        $this->app->bind(MediaShareService::class);

        $this->app->bind(WorkspaceFileManagerService::class);
        $this->app->bind(MediaStorageTransferService::class);

        $this->app->singleton(OAuthService::class, fn ($app) => new OAuthService(
            $app['config']->get('tetranyble-storage.cloud_drives', []),
        ));

        $this->app->bind(ConnectedDriveService::class, fn ($app) => new ConnectedDriveService(
            $app->make(OAuthService::class),
            $app->make(FileSystemContract::class),
            $app->make(StorageService::class),
            $app->make(StorageTransferAuthorizer::class),
        ));

        $this->app->bind(DownloadService::class, fn ($app) => new DownloadService(
            $app->make(FileSystemContract::class),
            $app->make(ConnectedDriveService::class),
            $app->make(ResourceAccessControl::class),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        if ($this->shouldLoadActivityMigrations()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations/activities');
        }
        if ((bool) config('tetranyble-storage.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/storage.php');
        }

        $this->publishes([
            __DIR__.'/../config/storage.php' => config_path('storage.php'),
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
                \Tetranyble\Storage\Console\PurgeTemporaryMediaCommand::class,
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

    private function synchronizeConfigKeys(): void
    {
        $primary = $this->app['config']->get('storage', []);
        $legacy = $this->app['config']->get('tetranyble-storage', []);

        if (! is_array($primary)) {
            $primary = [];
        }

        if (! is_array($legacy)) {
            $legacy = [];
        }

        $resolved = array_replace_recursive($legacy, $primary);

        $this->app['config']->set('storage', $resolved);
        $this->app['config']->set('tetranyble-storage', $resolved);
    }

    private function migrationPublishPaths(string $directory): array
    {
        $migrations = glob($directory.'/*_*.php') ?: [];

        return collect($migrations)
            ->mapWithKeys(fn (string $path) => [$path => database_path('migrations/'.basename($path))])
            ->all();
    }
}
