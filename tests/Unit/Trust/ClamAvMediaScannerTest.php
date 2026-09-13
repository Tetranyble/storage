<?php

namespace Tetranyble\Storage\Tests\Unit\Trust;

use Mockery;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\FileSize;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\MimeType;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\StoragePath;
use Tetranyble\Storage\Modules\Trust\Domain\ValueObject\MediaScanId;
use Tetranyble\Storage\Modules\Trust\Domain\DTO\MediaScanTarget;
use Tetranyble\Storage\Modules\Trust\Domain\Enums\VirusScanStatus;
use Tetranyble\Storage\Modules\Trust\Infrastructure\ClamAvMediaScanner;
use Tetranyble\Storage\Tests\PackageTestCase;

class ClamAvMediaScannerTest extends PackageTestCase
{
    public function test_known_oversized_media_is_rejected_before_storage_is_read(): void
    {
        config()->set('tetranyble-storage.trust.virus_scanning.max_scan_bytes', 100);
        $files = Mockery::mock(FileSystemContract::class);
        $files->shouldNotReceive('readStream');

        $result = (new ClamAvMediaScanner($files))->scan(new MediaScanTarget(
            mediaId: new MediaScanId(1),
            disk: Disk::PRIVATE,
            path: new StoragePath('large.bin'),
            size: new FileSize(101),
            mimeType: new MimeType('application/octet-stream'),
            originalName: 'large.bin',
        ));

        $this->assertSame(VirusScanStatus::FAILED, $result->status);
        $this->assertStringContainsString('scan ceiling', (string) $result->message);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
