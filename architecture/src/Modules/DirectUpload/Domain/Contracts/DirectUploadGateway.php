<?php

namespace Tetranyble\Storage\Modules\DirectUpload\Domain\Contracts;

use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadObject;
use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadPart;
use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadProviderPlan;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;

interface DirectUploadGateway
{
    public function supports(Disk $disk): bool;

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
    ): DirectUploadProviderPlan;

    public function signSingle(
        Disk $disk,
        string $objectKey,
        ?string $mimeType,
        ?string $sha256,
        int $expiresInSeconds,
        ?int $expectedSize = null,
    ): DirectUploadProviderPlan;

    /** @return list<DirectUploadPart> */
    public function signParts(
        Disk $disk,
        string $objectKey,
        string $uploadId,
        array $partNumbers,
        int $expiresInSeconds,
        ?int $expectedSize = null,
        ?int $partSize = null,
    ): array;

    /**
     * @param list<array{part_number:int,etag:string,checksum_sha256?:string|null}> $parts
     */
    public function completeMultipart(
        Disk $disk,
        string $objectKey,
        string $uploadId,
        array $parts,
    ): void;

    public function abortMultipart(Disk $disk, string $objectKey, string $uploadId): void;

    public function inspect(Disk $disk, string $objectKey): DirectUploadObject;
}
