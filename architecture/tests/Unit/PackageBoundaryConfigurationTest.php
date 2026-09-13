<?php

namespace Tetranyble\Storage\Tests\Unit;

use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\StoragePlacementPolicy;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Storage\Infrastructure\Policies\ConfiguredStoragePlacementPolicy;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\MediaContentInspector;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\MediaDeliveryPolicy;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\MediaScanner;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\QuarantineStoragePolicy;
use Tetranyble\Storage\Modules\Trust\Infrastructure\ConfiguredMediaDeliveryPolicy;
use Tetranyble\Storage\Modules\Trust\Infrastructure\ConfiguredQuarantineStoragePolicy;
use Tetranyble\Storage\Modules\Trust\Infrastructure\FileSignatureMediaInspector;
use Tetranyble\Storage\Modules\Trust\Infrastructure\NullMediaScanner;
use Tetranyble\Storage\Tests\PackageTestCase;

class PackageBoundaryConfigurationTest extends PackageTestCase
{
    public function test_default_config_exposes_only_host_workspace_and_user_model_integration_points(): void
    {
        $config = require dirname(__DIR__, 2).'/config/tetranyble-storage.php';

        $this->assertSame(['workspace', 'user'], array_keys($config['models']));
    }

    public function test_storage_placement_policy_is_an_overrideable_package_contract(): void
    {
        $this->assertInstanceOf(ConfiguredStoragePlacementPolicy::class, app(StoragePlacementPolicy::class));
    }

    public function test_generic_disk_placement_is_configuration_driven(): void
    {
        config()->set('tetranyble-storage.placement.private_modules', ['sensitive']);
        config()->set('tetranyble-storage.placement.private_purposes', ['DOCUMENT']);
        $policy = new ConfiguredStoragePlacementPolicy;

        $this->assertSame(Disk::PRIVATE, $policy->preferredDisk(new MediaUploadOptions(module: 'sensitive')));
        $this->assertSame(Disk::PRIVATE, $policy->preferredDisk(new MediaUploadOptions(purpose: MediaPurpose::DOCUMENT)));
        $this->assertNull($policy->preferredDisk(new MediaUploadOptions(module: 'generic')));
    }

    public function test_trust_pipeline_contracts_have_safe_default_bindings(): void
    {
        $this->assertInstanceOf(NullMediaScanner::class, app(MediaScanner::class));
        $this->assertInstanceOf(FileSignatureMediaInspector::class, app(MediaContentInspector::class));
        $this->assertInstanceOf(ConfiguredMediaDeliveryPolicy::class, app(MediaDeliveryPolicy::class));
        $this->assertInstanceOf(ConfiguredQuarantineStoragePolicy::class, app(QuarantineStoragePolicy::class));
    }

    public function test_pre_release_compatibility_surfaces_are_not_part_of_the_first_release(): void
    {
        $root = dirname(__DIR__, 2);

        foreach ([
            'src/Application/Legacy/WorkspaceFileManagerService.php',
            'src/Facades/FileManager.php',
            'src/Concerns/InteractsWithMedia.php',
            'src/Concerns/LegacyDocumentMediaAccessors.php',
        ] as $relative) {
            $this->assertFileDoesNotExist($root.'/'.$relative);
        }
    }

    public function test_media_model_does_not_perform_filesystem_io_from_saving_hooks(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/src/Modules/Media/Infrastructure/Persistence/Eloquent/Models/Media.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString('static::saving', $source);
        $this->assertStringNotContainsString('hydrateImageDimensions', $source);
    }
}
