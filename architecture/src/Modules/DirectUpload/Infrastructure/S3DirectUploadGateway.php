<?php

namespace Tetranyble\Storage\Modules\DirectUpload\Infrastructure;

use Aws\S3\S3Client;
use RuntimeException;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Contracts\DirectUploadGateway;
use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadObject;
use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadPart;
use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadProviderPlan;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadMode;
use Tetranyble\Storage\Modules\DirectUpload\Domain\ValueObject\ETag;
use Tetranyble\Storage\Modules\DirectUpload\Domain\ValueObject\PartNumber;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\FileSize;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\MimeType;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\Sha256Checksum;

/**
 * S3-compatible direct-upload gateway.
 *
 * The AWS SDK remains optional. This class is only exercised when a configured
 * S3 disk is selected and Aws\\S3\\S3Client is available at runtime.
 */
final class S3DirectUploadGateway implements DirectUploadGateway
{
    /** @var array<string, S3Client> */
    private array $clients = [];

    public function supports(Disk $disk): bool
    {
        if (! in_array($disk, [Disk::S3_PRIVATE, Disk::S3_PUBLIC], true)) {
            return false;
        }

        if (! class_exists(S3Client::class)) {
            return false;
        }

        $config = $this->diskConfig($disk);

        return ($config['driver'] ?? null) === 's3'
            && is_string($config['bucket'] ?? null)
            && trim((string) $config['bucket']) !== '';
    }

    public function begin(
        Disk $disk,
        string $objectKey,
        int $expectedSize,
        ?string $mimeType,
        ?string $sha256,
        int $expiresInSeconds,
        int $multipartThreshold,
        int $partSize,
        int $initialPartCount,
    ): DirectUploadProviderPlan {
        $this->assertSupported($disk);
        $client = $this->client($disk);
        $bucket = $this->bucket($disk);

        if ($expectedSize < $multipartThreshold) {
            return $this->signSingle($disk, $objectKey, $mimeType, $sha256, $expiresInSeconds, $expectedSize);
        }

        $created = $client->createMultipartUpload(array_filter([
            'Bucket' => $bucket,
            'Key' => $this->providerKey($disk, $objectKey),
            'ContentType' => $mimeType,
        ], static fn ($value): bool => $value !== null && $value !== ''));
        $uploadId = (string) ($created['UploadId'] ?? '');
        if ($uploadId === '') {
            throw new RuntimeException('S3 did not return a multipart upload identifier.');
        }

        $totalParts = (int) ceil($expectedSize / $partSize);
        $initialCount = min($totalParts, max(0, $initialPartCount));
        $initial = $initialCount > 0 ? range(1, $initialCount) : [];

        try {
            $parts = $initial === [] ? [] : $this->signParts(
                $disk,
                $objectKey,
                $uploadId,
                $initial,
                $expiresInSeconds,
                $expectedSize,
                $partSize,
            );
        } catch (\Throwable $exception) {
            // createMultipartUpload succeeded, so a later signing failure must not
            // strand a provider-side multipart upload before the application has a
            // chance to persist its upload id.
            try {
                $client->abortMultipartUpload([
                    'Bucket' => $bucket,
                    'Key' => $this->providerKey($disk, $objectKey),
                    'UploadId' => $uploadId,
                ]);
            } catch (\Throwable) {
                // Preserve the original signing failure; provider lifecycle rules
                // may still reap abandoned multipart state automatically.
            }

            throw $exception;
        }

        return new DirectUploadProviderPlan(
            mode: DirectUploadMode::MULTIPART,
            uploadId: $uploadId,
            parts: $parts,
            partSize: $partSize,
            totalParts: $totalParts,
            expectedSize: $expectedSize,
        );
    }

    public function signSingle(
        Disk $disk,
        string $objectKey,
        ?string $mimeType,
        ?string $sha256,
        int $expiresInSeconds,
        ?int $expectedSize = null,
    ): DirectUploadProviderPlan {
        $this->assertSupported($disk);
        $params = [
            'Bucket' => $this->bucket($disk),
            'Key' => $this->providerKey($disk, $objectKey),
        ];
        $headers = [];

        if ($expectedSize !== null && $expectedSize > 0) {
            $params['ContentLength'] = $expectedSize;
        }

        if (is_string($mimeType) && $mimeType !== '') {
            $params['ContentType'] = $mimeType;
            $headers['content-type'] = $mimeType;
        }

        if (is_string($sha256) && $sha256 !== '') {
            $binary = hex2bin($sha256);
            if (! is_string($binary)) {
                throw new RuntimeException('Invalid SHA-256 supplied for direct upload signing.');
            }
            $checksum = base64_encode($binary);
            $params['ChecksumSHA256'] = $checksum;
            $headers['x-amz-checksum-sha256'] = $checksum;
        }

        $command = $this->client($disk)->getCommand('PutObject', $params);
        $request = $this->client($disk)->createPresignedRequest($command, '+'.$expiresInSeconds.' seconds');

        return new DirectUploadProviderPlan(
            mode: DirectUploadMode::SINGLE,
            url: (string) $request->getUri(),
            headers: $headers,
            expectedSize: $expectedSize,
        );
    }

    public function signParts(
        Disk $disk,
        string $objectKey,
        string $uploadId,
        array $partNumbers,
        int $expiresInSeconds,
        ?int $expectedSize = null,
        ?int $partSize = null,
    ): array {
        $this->assertSupported($disk);
        $client = $this->client($disk);
        $bucket = $this->bucket($disk);
        $parts = [];

        foreach ($partNumbers as $partNumber) {
            $partNumber = (int) $partNumber;
            $expectedPartSize = null;
            if ($expectedSize !== null && $expectedSize > 0 && $partSize !== null && $partSize > 0) {
                $offset = ($partNumber - 1) * $partSize;
                $remaining = max(0, $expectedSize - $offset);
                $expectedPartSize = min($partSize, $remaining);
            }

            $params = [
                'Bucket' => $bucket,
                'Key' => $this->providerKey($disk, $objectKey),
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
            ];
            if ($expectedPartSize !== null && $expectedPartSize > 0) {
                $params['ContentLength'] = $expectedPartSize;
            }

            $command = $client->getCommand('UploadPart', $params);
            $request = $client->createPresignedRequest($command, '+'.$expiresInSeconds.' seconds');
            $parts[] = new DirectUploadPart(
                partNumber: new PartNumber($partNumber),
                url: (string) $request->getUri(),
                expectedSize: $expectedPartSize !== null ? new FileSize($expectedPartSize) : null,
            );
        }

        return $parts;
    }

    public function completeMultipart(
        Disk $disk,
        string $objectKey,
        string $uploadId,
        array $parts,
    ): void {
        $this->assertSupported($disk);

        $completedParts = array_map(
            static fn (array $part): array => [
                'PartNumber' => (int) $part['part_number'],
                'ETag' => trim((string) $part['etag'], " \t\n\r\0\x0B\""),
            ],
            $parts,
        );

        $this->client($disk)->completeMultipartUpload([
            'Bucket' => $this->bucket($disk),
            'Key' => $this->providerKey($disk, $objectKey),
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $completedParts],
        ]);
    }

    public function abortMultipart(Disk $disk, string $objectKey, string $uploadId): void
    {
        $this->assertSupported($disk);

        $this->client($disk)->abortMultipartUpload([
            'Bucket' => $this->bucket($disk),
            'Key' => $this->providerKey($disk, $objectKey),
            'UploadId' => $uploadId,
        ]);
    }

    public function inspect(Disk $disk, string $objectKey): DirectUploadObject
    {
        $this->assertSupported($disk);
        $client = $this->client($disk);
        $params = [
            'Bucket' => $this->bucket($disk),
            'Key' => $this->providerKey($disk, $objectKey),
            'ChecksumMode' => 'ENABLED',
        ];

        try {
            $head = $client->headObject($params);
        } catch (\Throwable $exception) {
            // Some S3-compatible implementations do not understand ChecksumMode.
            unset($params['ChecksumMode']);
            $head = $client->headObject($params);
        }

        $checksumType = is_string($head['ChecksumType'] ?? null)
            ? strtoupper((string) $head['ChecksumType'])
            : null;

        return new DirectUploadObject(
            size: new FileSize((int) ($head['ContentLength'] ?? 0)),
            mimeType: is_string($head['ContentType'] ?? null) ? new MimeType($head['ContentType']) : null,
            // Multipart COMPOSITE checksums are not the full byte-stream digest.
            // Unsafe provider checksums are omitted so finalization streams and verifies.
            sha256: ($checksum = $this->checksumHex($head['ChecksumSHA256'] ?? null, $checksumType)) !== null ? new Sha256Checksum($checksum) : null,
            etag: is_string($head['ETag'] ?? null) ? new ETag(trim($head['ETag'], '"')) : null,
        );
    }

    private function assertSupported(Disk $disk): void
    {
        if (! $this->supports($disk)) {
            throw new RuntimeException(sprintf(
                'Direct S3 uploads are unavailable for disk [%s]. Install league/flysystem-aws-s3-v3 and configure an S3 disk with a bucket.',
                $disk->value,
            ));
        }
    }

    private function client(Disk $disk): S3Client
    {
        if (isset($this->clients[$disk->value])) {
            return $this->clients[$disk->value];
        }

        $config = $this->diskConfig($disk);
        $clientConfig = [
            'version' => 'latest',
            'region' => (string) ($config['region'] ?? 'us-east-1'),
        ];

        if (! empty($config['endpoint'])) {
            $clientConfig['endpoint'] = $config['endpoint'];
        }
        if (array_key_exists('use_path_style_endpoint', $config)) {
            $clientConfig['use_path_style_endpoint'] = (bool) $config['use_path_style_endpoint'];
        }

        if (! empty($config['key']) && ! empty($config['secret'])) {
            $credentials = [
                'key' => (string) $config['key'],
                'secret' => (string) $config['secret'],
            ];
            if (! empty($config['token'])) {
                $credentials['token'] = (string) $config['token'];
            }
            $clientConfig['credentials'] = $credentials;
        }

        /** @var S3Client $client */
        $client = new S3Client($clientConfig);
        $this->clients[$disk->value] = $client;

        return $client;
    }

    private function providerKey(Disk $disk, string $objectKey): string
    {
        $config = $this->diskConfig($disk);
        $root = trim((string) ($config['root'] ?? ''), '/');
        $objectKey = ltrim($objectKey, '/');

        return $root === '' ? $objectKey : $root.'/'.$objectKey;
    }

    private function bucket(Disk $disk): string
    {
        return (string) $this->diskConfig($disk)['bucket'];
    }

    private function diskConfig(Disk $disk): array
    {
        $config = config('filesystems.disks.'.$disk->value, []);

        return is_array($config) ? $config : [];
    }

    private function checksumHex(mixed $checksum, ?string $checksumType = null): ?string
    {
        if ($checksumType === 'COMPOSITE') {
            return null;
        }

        if (! is_string($checksum) || $checksum === '' || str_contains($checksum, '-')) {
            return null;
        }

        $decoded = base64_decode($checksum, true);

        return is_string($decoded) && strlen($decoded) === 32 ? bin2hex($decoded) : null;
    }
}
