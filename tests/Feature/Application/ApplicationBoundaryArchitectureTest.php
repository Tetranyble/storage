<?php

namespace Tetranyble\Storage\Tests\Feature\Application;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Tetranyble\Storage\Application\Media\DeleteMedia;
use Tetranyble\Storage\Application\Media\MoveMedia;
use Tetranyble\Storage\Application\Media\RenameMedia;
use Tetranyble\Storage\Application\Media\RestoreMedia;
use Tetranyble\Storage\Application\Media\SetCurrentMedia;
use Tetranyble\Storage\Application\Media\TrashMedia;
use Tetranyble\Storage\Application\Media\UpdateMedia;
use Tetranyble\Storage\Application\Media\UploadMedia;
use Tetranyble\Storage\Application\Queries\GetMedia;
use Tetranyble\Storage\Application\Uploads\ImportRemoteMedia;
use Tetranyble\Storage\Application\Uploads\ResumableUploadSessionGuard;
use Tetranyble\Storage\Application\Uploads\StartResumableUpload;
use Tetranyble\Storage\Contracts\ActivityFeed;
use Tetranyble\Storage\Contracts\RemoteMediaImporter;
use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\Contracts\MediaUploader;
use Tetranyble\Storage\Domain\FileSystem\MediaService;
use Tetranyble\Storage\Domain\FileSystem\RemoteMediaDownloadService;
use Tetranyble\Storage\Domain\FileSystem\StorageLifecycleService;
use Tetranyble\Storage\Domain\FileSystem\StorageOrphanService;
use Tetranyble\Storage\Domain\FileSystem\StorageService;
use Tetranyble\Storage\Domain\Media\CurrentMediaSelectionService;
use Tetranyble\Storage\Domain\Media\MediaDeletionService;
use Tetranyble\Storage\Domain\Media\MediaLibraryService;
use Tetranyble\Storage\Domain\Media\MediaRelocationService;
use Tetranyble\Storage\Domain\Media\MediaStorageTransferService;
use Tetranyble\Storage\Domain\Media\MediaVersioningService;
use Tetranyble\Storage\Domain\Media\WorkspaceFileManagerService;
use Tetranyble\Storage\Domain\Media\WorkspaceFileQueryService;
use Tetranyble\Storage\Http\Controllers\ChunkedMediaUploadController;
use Tetranyble\Storage\Http\Controllers\MediaController;
use Tetranyble\Storage\Http\Controllers\MediaLibraryController;

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
        $this->assertNotContains(WorkspaceFileManagerService::class, $dependencies);
    }

    public function test_chunked_controller_uses_application_guards_for_session_creation_and_access(): void
    {
        $dependencies = $this->constructorDependencies(ChunkedMediaUploadController::class);

        $this->assertContains(StartResumableUpload::class, $dependencies);
        $this->assertContains(ResumableUploadSessionGuard::class, $dependencies);
        $this->assertNotContains(WorkspaceFileManagerService::class, $dependencies);
    }

    public function test_library_controller_routes_media_mutations_through_application_use_cases(): void
    {
        $dependencies = $this->constructorDependencies(MediaLibraryController::class);

        foreach ([UploadMedia::class, TrashMedia::class, RestoreMedia::class, DeleteMedia::class, MoveMedia::class, RenameMedia::class] as $expected) {
            $this->assertContains($expected, $dependencies);
        }
    }

    public function test_library_controller_routes_read_payloads_through_the_query_service(): void
    {
        $dependencies = $this->constructorDependencies(MediaLibraryController::class);

        $this->assertContains(WorkspaceFileQueryService::class, $dependencies);
    }

    public function test_media_application_mutations_no_longer_depend_on_the_workspace_manager(): void
    {
        foreach ([MoveMedia::class, RenameMedia::class, SetCurrentMedia::class, DeleteMedia::class] as $useCase) {
            $this->assertNotContains(
                WorkspaceFileManagerService::class,
                $this->constructorDependencies($useCase),
                $useCase.' must not delegate its business operation back to WorkspaceFileManagerService.',
            );
        }

        $this->assertContains(MediaRelocationService::class, $this->constructorDependencies(MoveMedia::class));
        $this->assertContains(MediaRelocationService::class, $this->constructorDependencies(RenameMedia::class));
        $this->assertContains(CurrentMediaSelectionService::class, $this->constructorDependencies(SetCurrentMedia::class));
        $this->assertContains(MediaDeletionService::class, $this->constructorDependencies(DeleteMedia::class));
    }

    public function test_media_library_and_media_service_do_not_form_a_constructor_cycle(): void
    {
        $libraryDependencies = $this->constructorDependencies(MediaLibraryService::class);
        $mediaServiceDependencies = $this->constructorDependencies(MediaService::class);

        $this->assertContains(MediaDeletionService::class, $libraryDependencies);
        $this->assertNotContains(MediaService::class, $libraryDependencies);
        $this->assertContains(MediaLibraryService::class, $mediaServiceDependencies);
    }

    public function test_media_service_delegates_cross_cutting_lifecycle_and_remote_transfer_concerns(): void
    {
        $dependencies = $this->constructorDependencies(MediaService::class);

        $this->assertContains(MediaDeletionService::class, $dependencies);
        $this->assertContains(CurrentMediaSelectionService::class, $dependencies);
        $this->assertContains(RemoteMediaDownloadService::class, $dependencies);

        $source = file_get_contents(__DIR__.'/../../../src/Domain/FileSystem/MediaService.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('Http::timeout(', $source);
        $this->assertStringNotContainsString('function downloadRemoteToDisk(', $source);
    }

    public function test_workspace_manager_no_longer_owns_low_level_storage_or_query_dependencies(): void
    {
        $dependencies = $this->constructorDependencies(WorkspaceFileManagerService::class);

        $this->assertContains(WorkspaceFileQueryService::class, $dependencies);
        $this->assertContains(MediaRelocationService::class, $dependencies);
        $this->assertContains(MediaDeletionService::class, $dependencies);
        $this->assertNotContains(FileSystemContract::class, $dependencies);
        $this->assertNotContains(StorageService::class, $dependencies);
        $this->assertNotContains(ActivityFeed::class, $dependencies);
    }

    public function test_media_library_contains_no_service_locator_resolution_of_media_service(): void
    {
        $source = file_get_contents(__DIR__.'/../../../src/Domain/Media/MediaLibraryService.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString('app(\\Tetranyble\\Storage\\Domain\\FileSystem\\MediaService::class)', $source);
        $this->assertStringNotContainsString('app(MediaService::class)', $source);
    }


    public function test_storage_lifecycle_boundaries_use_compensation_services_instead_of_destructive_moves(): void
    {
        $mediaServiceDependencies = $this->constructorDependencies(MediaService::class);
        $this->assertContains(StorageLifecycleService::class, $mediaServiceDependencies);
        $this->assertContains(StorageOrphanService::class, $mediaServiceDependencies);

        $this->assertContains(StorageOrphanService::class, $this->constructorDependencies(MediaDeletionService::class));
        $this->assertContains(StorageOrphanService::class, $this->constructorDependencies(MediaRelocationService::class));
        $this->assertContains(StorageLifecycleService::class, $this->constructorDependencies(MediaStorageTransferService::class));
        $this->assertContains(MediaDeletionService::class, $this->constructorDependencies(MediaVersioningService::class));

        foreach ([
            __DIR__.'/../../../src/Domain/Media/MediaRelocationService.php',
            __DIR__.'/../../../src/Domain/Media/MediaStorageTransferService.php',
            __DIR__.'/../../../src/Domain/Media/MediaLibraryService.php',
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);
            $this->assertStringNotContainsString('->move(', $source, $path.' must not destructively move an object before DB commit.');
        }
    }

    private function constructorDependencies(string $class): array
    {
        $constructor = (new ReflectionClass($class))->getConstructor();
        $this->assertNotNull($constructor);

        return array_values(array_filter(array_map(
            static function ($parameter): ?string {
                $type = $parameter->getType();

                return $type instanceof ReflectionNamedType && ! $type->isBuiltin()
                    ? $type->getName()
                    : null;
            },
            $constructor->getParameters(),
        )));
    }
}
