<?php

namespace Tetranyble\Storage\Tests\Unit\Trust;

use Mockery;
use PHPUnit\Framework\TestCase;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\FileSize;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\MimeType;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\StoragePath;
use Tetranyble\Storage\Modules\Trust\Domain\DTO\MediaScanTarget;
use Tetranyble\Storage\Modules\Trust\Domain\ValueObject\MediaScanId;
use Tetranyble\Storage\Modules\Trust\Infrastructure\FileSignatureMediaInspector;

class FileSignatureMediaInspectorTest extends TestCase
{
    public function test_detects_pdf_from_stored_bytes_instead_of_extension(): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n");
        rewind($stream);

        $files = Mockery::mock(FileSystemContract::class);
        $files->shouldReceive('readStream')
            ->once()
            ->with('uploads/not-really-an-image.jpg', Disk::PRIVATE)
            ->andReturn($stream);

        $inspection = (new FileSignatureMediaInspector($files))->inspect(new MediaScanTarget(
            mediaId: new MediaScanId(10),
            disk: Disk::PRIVATE,
            path: new StoragePath('uploads/not-really-an-image.jpg'),
            size: new FileSize(48),
            mimeType: new MimeType('image/jpeg'),
            originalName: 'portrait.jpg',
        ));

        $this->assertSame('application/pdf', $inspection->detectedMimeType);
        $this->assertFalse($inspection->isCompatibleWith('image/jpeg'));
    }

    public function test_empty_stored_object_is_reported_as_inconclusive_empty_content(): void
    {
        $stream = fopen('php://temp', 'w+b');
        $files = Mockery::mock(FileSystemContract::class);
        $files->shouldReceive('readStream')->once()->andReturn($stream);

        $inspection = (new FileSignatureMediaInspector($files))->inspect(new MediaScanTarget(
            mediaId: new MediaScanId(11),
            disk: Disk::PRIVATE,
            path: new StoragePath('uploads/empty.txt'),
            size: new FileSize(0),
            mimeType: new MimeType('text/plain'),
            originalName: 'empty.txt',
        ));

        $this->assertSame('application/x-empty', $inspection->detectedMimeType);
        $this->assertTrue($inspection->isCompatibleWith('text/plain'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
