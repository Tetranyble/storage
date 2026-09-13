<?php

namespace Tetranyble\Storage\Tests\Integration;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Contracts\DirectUploadGateway;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadMode;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Tests\PackageTestCase;

class S3ProviderContractTest extends PackageTestCase
{
    private string $bucket;

    private string $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $endpoint = getenv('STORAGE_TEST_S3_ENDPOINT');
        if (! is_string($endpoint) || $endpoint === '') {
            $this->markTestSkipped('Set STORAGE_TEST_S3_ENDPOINT to run the S3-compatible provider contract tests.');
        }

        $this->endpoint = $endpoint;
        $this->bucket = (string) (getenv('STORAGE_TEST_S3_BUCKET') ?: 'tetranyble-storage-test');
        $key = (string) (getenv('STORAGE_TEST_S3_KEY') ?: 'minioadmin');
        $secret = (string) (getenv('STORAGE_TEST_S3_SECRET') ?: 'minioadmin');

        config()->set('filesystems.disks.s3-private', [
            'driver' => 's3',
            'key' => $key,
            'secret' => $secret,
            'region' => 'us-east-1',
            'bucket' => $this->bucket,
            'endpoint' => $this->endpoint,
            'use_path_style_endpoint' => true,
            'throw' => true,
            'root' => 'package-contract',
        ]);
        Storage::forgetDisk('s3-private');

        $client = $this->client($key, $secret);
        if (! $client->doesBucketExistV2($this->bucket)) {
            $client->createBucket(['Bucket' => $this->bucket]);
            $client->waitUntil('BucketExists', ['Bucket' => $this->bucket]);
        }
    }

    public function test_filesystem_contract_round_trips_against_s3_compatible_storage(): void
    {
        $files = $this->app->make(FileSystemContract::class);
        $source = 'contract/'.bin2hex(random_bytes(6)).'.txt';
        $copy = str_replace('.txt', '-copy.txt', $source);
        $body = 'provider-contract-'.bin2hex(random_bytes(16));

        $this->assertTrue($files->put($source, $body, Disk::S3_PRIVATE));
        $this->assertTrue($files->exists($source, Disk::S3_PRIVATE));
        $this->assertSame(strlen($body), $files->size($source, Disk::S3_PRIVATE));
        $this->assertSame($body, $files->get($source, Disk::S3_PRIVATE));
        $this->assertTrue($files->copy($source, $copy, Disk::S3_PRIVATE, Disk::S3_PRIVATE));
        $this->assertSame($body, $files->get($copy, Disk::S3_PRIVATE));

        // Raw provider lookup proves the configured Flysystem root and the direct
        // AWS client agree on the same physical object key.
        $head = $this->client()->headObject([
            'Bucket' => $this->bucket,
            'Key' => 'package-contract/'.$source,
        ]);
        $this->assertSame(strlen($body), (int) $head['ContentLength']);

        $this->assertTrue($files->delete($source, Disk::S3_PRIVATE));
        $this->assertTrue($files->delete($copy, Disk::S3_PRIVATE));
    }

    public function test_direct_upload_gateway_signs_and_inspects_the_same_rooted_object(): void
    {
        $gateway = $this->app->make(DirectUploadGateway::class);
        $key = 'direct/'.bin2hex(random_bytes(6)).'.bin';
        $body = 'presigned-contract-body';

        $this->assertTrue($gateway->supports(Disk::S3_PRIVATE));
        $plan = $gateway->signSingle(
            Disk::S3_PRIVATE,
            $key,
            'application/octet-stream',
            null,
            300,
            strlen($body),
        );
        $this->assertSame(DirectUploadMode::SINGLE, $plan->mode);
        $this->assertNotSame('', (string) $plan->url);

        $headers = array_merge($plan->headers, ['content-length' => (string) strlen($body)]);
        $headerText = implode("\r\n", array_map(
            static fn (string $name, string $value): string => $name.': '.$value,
            array_keys($headers),
            array_values($headers),
        ));
        $context = stream_context_create(['http' => [
            'method' => 'PUT',
            'header' => $headerText,
            'content' => $body,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents((string) $plan->url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        $this->assertMatchesRegularExpression('/\s2\d\d\s/', $statusLine, 'Presigned PUT failed: '.$statusLine.' '.(string) $response);

        $object = $gateway->inspect(Disk::S3_PRIVATE, $key);
        $this->assertSame(strlen($body), $object->size->bytes);

        $files = $this->app->make(FileSystemContract::class);
        $this->assertSame($body, $files->get($key, Disk::S3_PRIVATE));
        $files->delete($key, Disk::S3_PRIVATE);
    }

    public function test_multipart_plan_can_be_created_and_aborted_against_provider(): void
    {
        $gateway = $this->app->make(DirectUploadGateway::class);
        $key = 'multipart/'.bin2hex(random_bytes(6)).'.bin';

        $plan = $gateway->begin(
            Disk::S3_PRIVATE,
            $key,
            12 * 1024 * 1024,
            'application/octet-stream',
            null,
            300,
            1024,
            5 * 1024 * 1024,
            2,
        );

        $this->assertSame(DirectUploadMode::MULTIPART, $plan->mode);
        $this->assertNotNull($plan->uploadId);
        $this->assertCount(2, $plan->parts);
        $this->assertSame(3, $plan->totalParts);

        $gateway->abortMultipart(Disk::S3_PRIVATE, $key, (string) $plan->uploadId);
        $this->addToAssertionCount(1);
    }

    public function test_multipart_upload_can_complete_and_be_inspected_end_to_end(): void
    {
        $gateway = $this->app->make(DirectUploadGateway::class);
        $key = 'multipart-complete/'.bin2hex(random_bytes(6)).'.bin';
        $partSize = 5 * 1024 * 1024;
        $lastSize = 1024 * 1024;
        $totalSize = $partSize + $lastSize;

        $plan = $gateway->begin(
            Disk::S3_PRIVATE,
            $key,
            $totalSize,
            'application/octet-stream',
            null,
            300,
            1,
            $partSize,
            0,
        );

        $this->assertSame(DirectUploadMode::MULTIPART, $plan->mode);
        $this->assertSame(2, $plan->totalParts);
        $this->assertNotNull($plan->uploadId);

        $client = $this->client();
        $completed = [];
        foreach ([1 => $partSize, 2 => $lastSize] as $partNumber => $size) {
            $result = $client->uploadPart([
                'Bucket' => $this->bucket,
                'Key' => 'package-contract/'.$key,
                'UploadId' => (string) $plan->uploadId,
                'PartNumber' => $partNumber,
                'Body' => str_repeat(chr(64 + $partNumber), $size),
            ]);
            $completed[] = [
                'part_number' => $partNumber,
                'etag' => (string) $result['ETag'],
            ];
        }

        $gateway->completeMultipart(Disk::S3_PRIVATE, $key, (string) $plan->uploadId, $completed);
        $object = $gateway->inspect(Disk::S3_PRIVATE, $key);

        $this->assertSame($totalSize, $object->size->bytes);
        $this->assertTrue($this->app->make(FileSystemContract::class)->delete($key, Disk::S3_PRIVATE));
    }

    private function client(?string $key = null, ?string $secret = null): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => $this->endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $key ?? (string) (getenv('STORAGE_TEST_S3_KEY') ?: 'minioadmin'),
                'secret' => $secret ?? (string) (getenv('STORAGE_TEST_S3_SECRET') ?: 'minioadmin'),
            ],
        ]);
    }
}
