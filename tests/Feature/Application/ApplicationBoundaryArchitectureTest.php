<?php

namespace Tetranyble\Storage\Tests\Feature\Application;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Tetranyble\Storage\Modules\Folder\Application\CreateFolder;
use Tetranyble\Storage\Modules\Folder\Application\EmptyTrash;
use Tetranyble\Storage\Modules\Media\Application\DeleteMedia;
use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaDeletion;
use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaRelocation;
use Tetranyble\Storage\Modules\Versioning\Application\Contracts\CurrentMediaSelection;
use Tetranyble\Storage\Modules\Media\Infrastructure\Application\MediaLibraryService;
use Tetranyble\Storage\Modules\Media\Application\MoveMedia;
use Tetranyble\Storage\Modules\Media\Application\RenameMedia;
use Tetranyble\Storage\Modules\Media\Application\RestoreMedia;
use Tetranyble\Storage\Modules\Media\Application\SetCurrentMedia;
use Tetranyble\Storage\Modules\Media\Application\TrashMedia;
use Tetranyble\Storage\Modules\Media\Application\UpdateMedia;
use Tetranyble\Storage\Modules\Media\Application\UploadMedia;
use Tetranyble\Storage\Modules\Media\Application\Queries\GetMedia;
use Tetranyble\Storage\Modules\Workspace\Application\Contracts\WorkspaceReadModel;
use Tetranyble\Storage\Modules\Sharing\Application\CreateMediaShare;
use Tetranyble\Storage\Modules\Sharing\Application\RevokeMediaShare;
use Tetranyble\Storage\Modules\Remote\Application\ImportRemoteMedia;
use Tetranyble\Storage\Modules\Upload\Application\ResumableUploadSessionGuard;
use Tetranyble\Storage\Modules\Upload\Application\StartResumableUpload;
use Tetranyble\Storage\Modules\Versioning\Infrastructure\Application\CurrentMediaSelectionService;
use Tetranyble\Storage\Modules\Versioning\Infrastructure\Application\MediaVersioningService;
use Tetranyble\Storage\Modules\Remote\Application\Contracts\RemoteMediaImporter;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\MediaUploader;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\StoragePlacementPolicy;
use Tetranyble\Storage\Http\Controllers\ChunkedMediaUploadController;
use Tetranyble\Storage\Http\Controllers\MediaController;
use Tetranyble\Storage\Http\Controllers\MediaLibraryController;
use Tetranyble\Storage\Modules\Remote\Infrastructure\RemoteMediaDownloadService;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\Media\MediaDeletionService;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\Media\MediaRelocationService;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\Media\MediaStoragePathResolver;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\Media\MediaStorageTransferService;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\MediaService;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageLifecycleService;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageOrphanService;
use Tetranyble\Storage\Modules\Upload\Infrastructure\ResumableUploadOptionsCodec;
use Tetranyble\Storage\Modules\Upload\Infrastructure\ResumableUploadService;

class ApplicationBoundaryArchitectureTest extends TestCase
{
    public function test_direct_media_controller_depends_on_application_use_cases_not_raw_business_services(): void
    {
        $dependencies = $this->constructorDependencies(MediaController::class);

        foreach ([UploadMedia::class, ImportRemoteMedia::class, GetMedia::class, UpdateMedia::class, TrashMedia::class, SetCurrentMedia::class] as $expected) {
            $this->assertContains($expected, $dependencies);
        }
        $this->assertNotContains(MediaUploader::class, $dependencies);
        $this->assertNotContains(RemoteMediaImporter::class, $dependencies);
    }

    public function test_chunked_controller_uses_application_guards_for_session_creation_and_access(): void
    {
        $dependencies = $this->constructorDependencies(ChunkedMediaUploadController::class);
        $this->assertContains(StartResumableUpload::class, $dependencies);
        $this->assertContains(ResumableUploadSessionGuard::class, $dependencies);
    }

    public function test_library_controller_routes_mutations_and_queries_through_focused_application_services(): void
    {
        $dependencies = $this->constructorDependencies(MediaLibraryController::class);
        foreach ([
            UploadMedia::class,
            TrashMedia::class,
            RestoreMedia::class,
            DeleteMedia::class,
            MoveMedia::class,
            RenameMedia::class,
            CreateFolder::class,
            EmptyTrash::class,
            CreateMediaShare::class,
            RevokeMediaShare::class,
            WorkspaceReadModel::class,
        ] as $expected) {
            $this->assertContains($expected, $dependencies);
        }
    }

    public function test_media_mutations_use_focused_lifecycle_services(): void
    {
        $this->assertContains(MediaRelocation::class, $this->constructorDependencies(MoveMedia::class));
        $this->assertContains(MediaRelocation::class, $this->constructorDependencies(RenameMedia::class));
        $this->assertContains(CurrentMediaSelection::class, $this->constructorDependencies(SetCurrentMedia::class));
        $this->assertContains(MediaDeletion::class, $this->constructorDependencies(DeleteMedia::class));
    }

    public function test_media_library_and_media_service_do_not_form_a_constructor_cycle(): void
    {
        $libraryDependencies = $this->constructorDependencies(MediaLibraryService::class);
        $mediaServiceDependencies = $this->constructorDependencies(MediaService::class);

        $this->assertContains(MediaDeletionService::class, $libraryDependencies);
        $this->assertNotContains(MediaService::class, $libraryDependencies);
        $this->assertContains(MediaLibraryService::class, $mediaServiceDependencies);
    }

    public function test_media_service_delegates_cross_cutting_lifecycle_remote_and_path_concerns(): void
    {
        $dependencies = $this->constructorDependencies(MediaService::class);
        $this->assertContains(MediaDeletionService::class, $dependencies);
        $this->assertContains(CurrentMediaSelectionService::class, $dependencies);
        $this->assertContains(RemoteMediaDownloadService::class, $dependencies);
        $this->assertContains(MediaStoragePathResolver::class, $dependencies);
        $this->assertNotContains(StoragePlacementPolicy::class, $dependencies);

        $source = (string) file_get_contents(__DIR__.'/../../../src/Modules/Media/Infrastructure/Storage/MediaService.php');
        $this->assertStringNotContainsString('Http::timeout(', $source);
        $this->assertStringNotContainsString('function downloadRemoteToDisk(', $source);
        $this->assertStringNotContainsString('function resolveUploadDirectory(', $source);
        $this->assertStringNotContainsString('function generateStoredFilename(', $source);
    }

    public function test_resumable_service_delegates_option_persistence_format(): void
    {
        $dependencies = $this->constructorDependencies(ResumableUploadService::class);
        $this->assertContains(ResumableUploadOptionsCodec::class, $dependencies);

        $source = (string) file_get_contents(__DIR__.'/../../../src/Modules/Upload/Infrastructure/ResumableUploadService.php');
        $this->assertStringNotContainsString('function serializeUploadOptions(', $source);
        $this->assertStringNotContainsString('function hydrateUploadOptions(', $source);
    }

    public function test_media_library_contains_no_service_locator_resolution_of_media_service(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../src/Modules/Media/Infrastructure/Application/MediaLibraryService.php');
        $this->assertStringNotContainsString('app(MediaService::class)', $source);
        $this->assertStringNotContainsString('app(\\Tetranyble\\Storage\\Infrastructure\\Storage\\MediaService::class)', $source);
    }

    public function test_storage_lifecycle_boundaries_use_compensation_instead_of_destructive_moves(): void
    {
        $mediaServiceDependencies = $this->constructorDependencies(MediaService::class);
        $this->assertContains(StorageLifecycleService::class, $mediaServiceDependencies);
        $this->assertContains(StorageOrphanService::class, $mediaServiceDependencies);
        $this->assertContains(StorageOrphanService::class, $this->constructorDependencies(MediaDeletionService::class));
        $this->assertContains(StorageOrphanService::class, $this->constructorDependencies(MediaRelocationService::class));
        $this->assertContains(StorageLifecycleService::class, $this->constructorDependencies(MediaStorageTransferService::class));
        $this->assertContains(MediaDeletionService::class, $this->constructorDependencies(MediaVersioningService::class));

        foreach ([
            __DIR__.'/../../../src/Modules/Media/Infrastructure/Storage/Media/MediaRelocationService.php',
            __DIR__.'/../../../src/Modules/Media/Infrastructure/Storage/Media/MediaStorageTransferService.php',
            __DIR__.'/../../../src/Modules/Media/Infrastructure/Application/MediaLibraryService.php',
        ] as $path) {
            $this->assertStringNotContainsString('->move(', (string) file_get_contents($path));
        }
    }

    /** @return list<string> */
    private function constructorDependencies(string $class): array
    {
        $constructor = (new ReflectionClass($class))->getConstructor();
        $this->assertNotNull($constructor);

        return array_values(array_filter(array_map(
            static function ($parameter): ?string {
                $type = $parameter->getType();
                return $type instanceof ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : null;
            },
            $constructor->getParameters(),
        )));
    }
}
