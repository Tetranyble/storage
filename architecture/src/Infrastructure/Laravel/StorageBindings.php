<?php

namespace Tetranyble\Storage\Infrastructure\Laravel;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;
use Tetranyble\Storage\Http\Contracts\WorkspaceContext;
use Tetranyble\Storage\Http\Routing\LaravelShareUrlGenerator;
use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Application\Contracts\StorageTransferAuthorizer;
use Tetranyble\Storage\Modules\Access\Application\Contracts\WorkspaceResourceLocator;
use Tetranyble\Storage\Modules\Access\Infrastructure\Application\Adapters\EloquentResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Infrastructure\Application\EloquentWorkspaceResourceLocator;
use Tetranyble\Storage\Modules\Activity\Application\Contracts\ActivityFeed;
use Tetranyble\Storage\Modules\Activity\Application\Contracts\ActivityLogger;
use Tetranyble\Storage\Modules\Activity\Infrastructure\DatabaseActivityFeed;
use Tetranyble\Storage\Modules\Activity\Infrastructure\DatabaseActivityLogger;
use Tetranyble\Storage\Modules\Activity\Infrastructure\NullActivityFeed;
use Tetranyble\Storage\Modules\Activity\Infrastructure\NullActivityLogger;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\CloudProviderDependencyGuard;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\ConnectedDriveDefaultCoordinator;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\ConnectedDriveService;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\OAuthService;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers\CloudProviderRegistry;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers\DefaultCloudProviderRegistryFactory;
use Tetranyble\Storage\Modules\DirectUpload\Application\Contracts\DirectUploadManager;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Contracts\DirectUploadGateway;
use Tetranyble\Storage\Modules\DirectUpload\Infrastructure\Application\Adapters\EloquentDirectUploadManager;
use Tetranyble\Storage\Modules\DirectUpload\Infrastructure\S3DirectUploadGateway;
use Tetranyble\Storage\Modules\Download\Infrastructure\Application\DownloadService;
use Tetranyble\Storage\Modules\Health\Infrastructure\Application\StorageHealthService;
use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaDeletion;
use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaLibrary;
use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaRelocation;
use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaRevisionWriter;
use Tetranyble\Storage\Modules\Media\Infrastructure\Application\Adapters\EloquentMediaDeletion;
use Tetranyble\Storage\Modules\Media\Infrastructure\Application\Adapters\EloquentMediaLibrary;
use Tetranyble\Storage\Modules\Media\Infrastructure\Application\Adapters\EloquentMediaRelocation;
use Tetranyble\Storage\Modules\Media\Infrastructure\Application\Adapters\EloquentMediaRevisionWriter;
use Tetranyble\Storage\Modules\Media\Infrastructure\Application\CommentService;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\Media\MediaDeletionService;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\Media\MediaRelocationService;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\Media\MediaStorageTransferService;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\MediaService;
use Tetranyble\Storage\Modules\Observability\Domain\Contracts\StorageTelemetry;
use Tetranyble\Storage\Modules\Observability\Infrastructure\LaravelStorageTelemetry;
use Tetranyble\Storage\Modules\Observability\Infrastructure\NullStorageTelemetry;
use Tetranyble\Storage\Modules\Processing\Application\Contracts\MediaProcessing;
use Tetranyble\Storage\Modules\Processing\Application\MediaDeliveryGuard;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Application\Adapters\EloquentMediaProcessing;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Application\MediaProcessingDispatcher;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Application\MediaProcessingService;
use Tetranyble\Storage\Modules\Processing\Infrastructure\ImageProcessing\MediaDerivativeService;
use Tetranyble\Storage\Modules\Processing\Infrastructure\ImageProcessing\MediaPostProcessor;
use Tetranyble\Storage\Modules\Remote\Application\Contracts\RemoteMediaImporter;
use Tetranyble\Storage\Modules\Remote\Application\Contracts\ResourceIdentity;
use Tetranyble\Storage\Modules\Remote\Domain\Contracts\RemoteUrlValidator;
use Tetranyble\Storage\Modules\Remote\Infrastructure\Persistence\Eloquent\EloquentResourceIdentity;
use Tetranyble\Storage\Modules\Remote\Infrastructure\RemoteMediaDownloadService;
use Tetranyble\Storage\Modules\Remote\Infrastructure\SafeRemoteUrlValidator;
use Tetranyble\Storage\Modules\Shared\Application\Contracts\ResourceState;
use Tetranyble\Storage\Modules\Shared\Application\Contracts\StorageEventPublisher;
use Tetranyble\Storage\Modules\Shared\Infrastructure\Persistence\Eloquent\EloquentResourceState;
use Tetranyble\Storage\Modules\Sharing\Application\Contracts\MediaShares;
use Tetranyble\Storage\Modules\Sharing\Application\Contracts\ShareUrlGenerator;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Application\Adapters\EloquentMediaShares;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Application\MediaShareService;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\MediaUploader;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\StoragePlacementPolicy;
use Tetranyble\Storage\Modules\Storage\Infrastructure\Application\Adapters\EloquentMediaUploader;
use Tetranyble\Storage\Modules\Storage\Infrastructure\FileSystem;
use Tetranyble\Storage\Modules\Storage\Infrastructure\Policies\ConfiguredStoragePlacementPolicy;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageLifecycleService;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageOrphanService;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageService;
use Tetranyble\Storage\Modules\Transfer\Application\AccessControlTransferAuthorizer;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\MediaContentInspector;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\MediaDeliveryPolicy;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\MediaScanner;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\QuarantineStoragePolicy;
use Tetranyble\Storage\Modules\Trust\Infrastructure\ClamAvMediaScanner;
use Tetranyble\Storage\Modules\Trust\Infrastructure\ConfiguredMediaDeliveryPolicy;
use Tetranyble\Storage\Modules\Trust\Infrastructure\ConfiguredQuarantineStoragePolicy;
use Tetranyble\Storage\Modules\Trust\Infrastructure\FileSignatureMediaInspector;
use Tetranyble\Storage\Modules\Trust\Infrastructure\NullMediaScanner;
use Tetranyble\Storage\Modules\Upload\Application\Contracts\ResumableUploadManager;
use Tetranyble\Storage\Modules\Upload\Application\Contracts\UploadLimits;
use Tetranyble\Storage\Modules\Upload\Infrastructure\Application\Adapters\EloquentResumableUploadManager;
use Tetranyble\Storage\Modules\Upload\Infrastructure\Laravel\ConfiguredUploadLimits;
use Tetranyble\Storage\Modules\Versioning\Application\Contracts\CurrentMediaSelection;
use Tetranyble\Storage\Modules\Versioning\Application\Contracts\MediaVersioning as MediaVersioningPort;
use Tetranyble\Storage\Modules\Versioning\Infrastructure\Application\Adapters\EloquentCurrentMediaSelection;
use Tetranyble\Storage\Modules\Versioning\Infrastructure\Application\Adapters\EloquentMediaVersioning;
use Tetranyble\Storage\Modules\Versioning\Infrastructure\Application\CurrentMediaSelectionService;
use Tetranyble\Storage\Modules\Versioning\Infrastructure\Application\MediaVersioningService;
use Tetranyble\Storage\Modules\Workspace\Application\Contracts\WorkspaceReadModel;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Queries\WorkspaceFileQueryService;

final class StorageBindings
{
    public static function register(Application $app): void
    {
        $app->bind(FileSystemContract::class, FileSystem::class);
        $app->bind(UploadLimits::class, ConfiguredUploadLimits::class);
        $app->bind(StorageEventPublisher::class, LaravelStorageEventPublisher::class);
        $app->bind(ResourceState::class, EloquentResourceState::class);
        $app->bind(ResourceIdentity::class, EloquentResourceIdentity::class);
        $app->bind(MediaUploader::class, EloquentMediaUploader::class);
        if (! $app->bound(RemoteMediaImporter::class)) {
            $app->bind(RemoteMediaImporter::class, MediaService::class);
        }
        if (! $app->bound(RemoteUrlValidator::class)) {
            $app->bind(RemoteUrlValidator::class, SafeRemoteUrlValidator::class);
        }
        $app->bind(ResumableUploadManager::class, EloquentResumableUploadManager::class);
        if (! $app->bound(DirectUploadGateway::class)) {
            $app->bind(DirectUploadGateway::class, S3DirectUploadGateway::class);
        }
        $app->bind(DirectUploadManager::class, EloquentDirectUploadManager::class);
        if (! $app->bound(StorageTelemetry::class)) {
            $app->bind(StorageTelemetry::class, function ($app) {
                if (! (bool) $app['config']->get('tetranyble-storage.observability.enabled', true)) {
                    return $app->make(NullStorageTelemetry::class);
                }

                $channel = $app['config']->get('tetranyble-storage.observability.log_channel');
                $logger = $app['log']->channel(is_string($channel) && $channel !== '' ? $channel : null);

                return new LaravelStorageTelemetry(
                    logger: $logger,
                    events: $app['events'],
                    logRecords: (bool) $app['config']->get('tetranyble-storage.observability.log_records', true),
                    dispatchRecords: (bool) $app['config']->get('tetranyble-storage.observability.dispatch_events', true),
                );
            });
        }
        $app->bind(StorageHealthService::class);
        if (! $app->bound(StoragePlacementPolicy::class)) {
            $app->bind(StoragePlacementPolicy::class, ConfiguredStoragePlacementPolicy::class);
        }
        if (! $app->bound(MediaDeliveryPolicy::class)) {
            $app->bind(MediaDeliveryPolicy::class, ConfiguredMediaDeliveryPolicy::class);
        }
        if (! $app->bound(MediaContentInspector::class)) {
            $app->bind(MediaContentInspector::class, FileSignatureMediaInspector::class);
        }
        if (! $app->bound(QuarantineStoragePolicy::class)) {
            $app->bind(QuarantineStoragePolicy::class, ConfiguredQuarantineStoragePolicy::class);
        }
        if (! $app->bound(MediaScanner::class)) {
            $app->bind(MediaScanner::class, function ($app) {
                if (! (bool) $app['config']->get('tetranyble-storage.trust.virus_scanning.enabled', false)) {
                    return $app->make(NullMediaScanner::class);
                }

                $scanner = $app['config']->get(
                    'tetranyble-storage.trust.virus_scanning.scanner',
                    ClamAvMediaScanner::class,
                );
                if (! is_string($scanner) || ! is_a($scanner, MediaScanner::class, true)) {
                    throw new RuntimeException('The configured media scanner must implement '.MediaScanner::class.'.');
                }

                return $app->make($scanner);
            });
        }
        $app->bind(MediaDeliveryGuard::class);
        $app->bind(MediaProcessingService::class);
        $app->bind(MediaProcessingDispatcher::class);
        $app->bind(RemoteMediaDownloadService::class);
        $app->bind(StorageOrphanService::class);
        $app->bind(StorageLifecycleService::class);
        if (! $app->bound(ActivityLogger::class)) {
            $app->bind(ActivityLogger::class, function ($app) {
                $logger = (bool) $app['config']->get('tetranyble-storage.activities.enabled', false)
                    ? DatabaseActivityLogger::class
                    : NullActivityLogger::class;

                return $app->make($logger);
            });
        }
        if (! $app->bound(ActivityFeed::class)) {
            $app->bind(ActivityFeed::class, function ($app) {
                $feed = (bool) $app['config']->get('tetranyble-storage.activities.enabled', false)
                    ? DatabaseActivityFeed::class
                    : NullActivityFeed::class;

                return $app->make($feed);
            });
        }
        if (! $app->bound(ResourceAccessControl::class)) {
            $app->bind(ResourceAccessControl::class, EloquentResourceAccessControl::class);
        }
        $app->bind(WorkspaceResourceLocator::class, EloquentWorkspaceResourceLocator::class);
        $app->bind(MediaLibrary::class, EloquentMediaLibrary::class);
        $app->bind(MediaDeletion::class, EloquentMediaDeletion::class);
        $app->bind(MediaRelocation::class, EloquentMediaRelocation::class);
        $app->bind(MediaRevisionWriter::class, EloquentMediaRevisionWriter::class);
        $app->bind(MediaProcessing::class, EloquentMediaProcessing::class);
        $app->bind(MediaShares::class, EloquentMediaShares::class);
        $app->bind(CurrentMediaSelection::class, EloquentCurrentMediaSelection::class);
        $app->bind(MediaVersioningPort::class, EloquentMediaVersioning::class);
        if (! $app->bound(WorkspaceContext::class)) {
            $app->bind(WorkspaceContext::class, function ($app) {
                $resolver = $app['config']->get('tetranyble-storage.workspace.resolver');
                if (! is_string($resolver) || ! is_a($resolver, WorkspaceContext::class, true)) {
                    throw new RuntimeException('The configured storage workspace resolver must implement '.WorkspaceContext::class.'.');
                }

                return $app->make($resolver);
            });
        }
        if (! $app->bound(StorageTransferAuthorizer::class)) {
            $app->bind(StorageTransferAuthorizer::class, function ($app) {
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

        $app->bind(CommentService::class, fn ($app) => new CommentService(
            $app->make(ResourceAccessControl::class),
            $app->make(StorageTelemetry::class),
        ));

        $app->bind(MediaDerivativeService::class);
        $app->bind(MediaPostProcessor::class, fn ($app) => new MediaPostProcessor(
            $app->make(FileSystemContract::class),
            $app->make(MediaDerivativeService::class),
        ));

        $app->bind(MediaVersioningService::class, fn ($app) => new MediaVersioningService(
            $app->make(MediaDeletionService::class),
            $app->make(ActivityLogger::class),
            $app->make(ActivityFeed::class),
        ));

        if (! $app->bound(ShareUrlGenerator::class)) {
            $app->bind(ShareUrlGenerator::class, LaravelShareUrlGenerator::class);
        }
        $app->bind(MediaShareService::class);
        $app->bind(MediaDeletionService::class);
        $app->bind(MediaRelocationService::class);
        $app->bind(CurrentMediaSelectionService::class);
        $app->bind(WorkspaceFileQueryService::class);
        $app->bind(WorkspaceReadModel::class, WorkspaceFileQueryService::class);

        $app->bind(MediaStorageTransferService::class);

        $app->singleton(CloudProviderDependencyGuard::class);
        $app->singleton(ConnectedDriveDefaultCoordinator::class);
        $app->singleton(CloudProviderRegistry::class, fn ($app) => DefaultCloudProviderRegistryFactory::make(
            $app->make(CloudProviderDependencyGuard::class),
        ));
        $app->singleton(OAuthService::class, fn ($app) => new OAuthService(
            $app['config']->get('tetranyble-storage.cloud_drives', []),
            $app->make(StorageTelemetry::class),
            $app->make(CloudProviderRegistry::class),
        ));

        $app->bind(ConnectedDriveService::class, fn ($app) => new ConnectedDriveService(
            $app->make(OAuthService::class),
            $app->make(FileSystemContract::class),
            $app->make(StorageService::class),
            $app->make(StorageTransferAuthorizer::class),
            $app->make(CloudProviderDependencyGuard::class),
            $app->make(StorageLifecycleService::class),
            $app->make(MediaProcessingDispatcher::class),
            $app->make(MediaDeliveryGuard::class),
            $app->make(QuarantineStoragePolicy::class),
            $app->make(CloudProviderRegistry::class),
            $app->make(ConnectedDriveDefaultCoordinator::class),
        ));
        $app->bind(DownloadService::class, fn ($app) => new DownloadService(
            $app->make(FileSystemContract::class),
            $app->make(ConnectedDriveService::class),
            $app->make(ResourceAccessControl::class),
            $app->make(MediaDeliveryGuard::class),
        ));
    }
}
