<?php

namespace Tetranyble\Storage\Tests\Unit\DirectUploads;

use Aws\S3\S3Client;
use ReflectionMethod;
use Tetranyble\Storage\Modules\DirectUpload\Infrastructure\S3DirectUploadGateway;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Tests\PackageTestCase;

class S3DirectUploadGatewayTest extends PackageTestCase
{
    public function test_support_requires_an_s3_disk_bucket_and_optional_sdk(): void
    {
        $gateway = new S3DirectUploadGateway;

        $this->assertFalse($gateway->supports(Disk::PRIVATE));
        $this->assertFalse($gateway->supports(Disk::S3_PRIVATE));

        config()->set('filesystems.disks.s3-private', [
            'driver' => 's3',
            'bucket' => 'direct-upload-tests',
            'region' => 'us-east-1',
        ]);

        $this->assertSame(class_exists(S3Client::class), $gateway->supports(Disk::S3_PRIVATE));
    }

    public function test_provider_key_honors_the_laravel_s3_disk_root_prefix(): void
    {
        config()->set('filesystems.disks.s3-private.root', 'tenant-storage/root');
        $gateway = new S3DirectUploadGateway;
        $method = new ReflectionMethod($gateway, 'providerKey');

        $this->assertSame(
            'tenant-storage/root/workspaces/1/direct/file.bin',
            $method->invoke($gateway, Disk::S3_PRIVATE, 'workspaces/1/direct/file.bin'),
        );
    }

    public function test_composite_multipart_sha256_is_not_mistaken_for_a_full_object_digest(): void
    {
        $gateway = new S3DirectUploadGateway;
        $method = new ReflectionMethod($gateway, 'checksumHex');
        $encoded = base64_encode(str_repeat('\\x01', 32));

        $this->assertNull($method->invoke($gateway, $encoded, 'COMPOSITE'));
        $this->assertSame(str_repeat('01', 32), $method->invoke($gateway, $encoded, 'FULL_OBJECT'));
        $this->assertSame(str_repeat('01', 32), $method->invoke($gateway, $encoded, null));
    }
}
