<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Tests\Unit\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tetranyble\Storage\Modules\DirectUpload\Domain\ValueObject\ETag;
use Tetranyble\Storage\Modules\DirectUpload\Domain\ValueObject\PartNumber;
use Tetranyble\Storage\Modules\Media\Domain\ValueObject\MediaId;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\FileSize;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\MimeType;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\Sha256Checksum;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\StoragePath;
use Tetranyble\Storage\Modules\Workspace\Domain\ValueObject\WorkspaceId;

final class ValueObjectsTest extends TestCase
{
    public function test_storage_values_normalize_and_preserve_valid_values(): void
    {
        $checksum = hash('sha256', 'payload');

        self::assertSame('folder/document.pdf', (new StoragePath('/folder/document.pdf'))->value);
        self::assertSame('text/plain', (new MimeType('Text/Plain; charset=UTF-8'))->value);
        self::assertSame($checksum, (new Sha256Checksum(strtoupper($checksum)))->value);
        self::assertSame(42, (new FileSize(42))->bytes);
        self::assertSame(7, (new MediaId(7))->value);
        self::assertSame('tenant-a', (new WorkspaceId('tenant-a'))->asString());
        self::assertSame(3, (new PartNumber(3))->value);
        self::assertSame('abc123', (new ETag(' abc123 '))->value);
    }

    #[DataProvider('invalidFactories')]
    public function test_invalid_domain_values_are_rejected(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    public static function invalidFactories(): iterable
    {
        yield 'negative file size' => [static fn () => new FileSize(-1)];
        yield 'path traversal' => [static fn () => new StoragePath('../secret.txt')];
        yield 'invalid mime' => [static fn () => new MimeType('not-a-mime')];
        yield 'invalid sha256' => [static fn () => new Sha256Checksum('abc')];
        yield 'invalid media id' => [static fn () => new MediaId(0)];
        yield 'invalid workspace id' => [static fn () => new WorkspaceId('')];
        yield 'invalid part number' => [static fn () => new PartNumber(0)];
        yield 'empty etag' => [static fn () => new ETag('')];
    }
}
