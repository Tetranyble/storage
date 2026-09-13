<?php

namespace Tetranyble\Storage\Tests\Fixtures\DirectUploads;

use RuntimeException;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Contracts\DirectUploadGateway;
use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadObject;
use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadPart;
use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadProviderPlan;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadMode;
use Tetranyble\Storage\Modules\DirectUpload\Domain\ValueObject\PartNumber;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\FileSize;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;

final class FakeDirectUploadGateway implements DirectUploadGateway
{
    public bool $supported = true;
    public bool $failBegin = false;
    public bool $failAbort = false;
    public int $abortCalls = 0;
    public int $completeCalls = 0;
    public array $signedParts = [];
    public ?DirectUploadObject $object = null;

    public function supports(Disk $disk): bool
    {
        return $this->supported && in_array($disk, [Disk::S3_PRIVATE, Disk::S3_PUBLIC], true);
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
        if ($this->failBegin) {
            throw new RuntimeException('provider start failed');
        }

        if ($expectedSize < $multipartThreshold) {
            return $this->signSingle($disk, $objectKey, $mimeType, $sha256, $expiresInSeconds, $expectedSize);
        }

        $total = (int) ceil($expectedSize / $partSize);
        $initialCount = min($total, max(0, $initialPartCount));
        $parts = $initialCount > 0 ? $this->signParts(
            $disk,
            $objectKey,
            'fake-upload-id',
            range(1, $initialCount),
            $expiresInSeconds,
            $expectedSize,
            $partSize,
        ) : [];

        return new DirectUploadProviderPlan(
            mode: DirectUploadMode::MULTIPART,
            uploadId: 'fake-upload-id',
            parts: $parts,
            partSize: $partSize,
            totalParts: $total,
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
        return new DirectUploadProviderPlan(
            mode: DirectUploadMode::SINGLE,
            url: 'https://uploads.example.test/'.rawurlencode($objectKey),
            headers: array_filter([
                'content-type' => $mimeType,
                'x-test-sha256' => $sha256,
            ]),
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
        foreach ($partNumbers as $number) {
            $this->signedParts[] = (int) $number;
        }

        return array_map(
            static fn ($number): DirectUploadPart => new DirectUploadPart(
                new PartNumber((int) $number),
                'https://uploads.example.test/part/'.(int) $number,
                expectedSize: $expectedSize !== null && $partSize !== null
                    ? new FileSize(min($partSize, max(0, $expectedSize - (((int) $number - 1) * $partSize))))
                    : null,
            ),
            $partNumbers,
        );
    }

    public function completeMultipart(Disk $disk, string $objectKey, string $uploadId, array $parts): void
    {
        $this->completeCalls++;
    }

    public function abortMultipart(Disk $disk, string $objectKey, string $uploadId): void
    {
        $this->abortCalls++;
        if ($this->failAbort) {
            throw new RuntimeException('abort failed');
        }
    }

    public function inspect(Disk $disk, string $objectKey): DirectUploadObject
    {
        return $this->object ?? throw new RuntimeException('object is not available');
    }
}
