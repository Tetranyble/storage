<?php

namespace Tetranyble\Storage\Modules\DirectUpload\Application\Contracts;

use Tetranyble\Storage\Modules\DirectUpload\Application\DTO\DirectUploadRequest;
use Tetranyble\Storage\Modules\DirectUpload\Application\DTO\DirectUploadStartResult;
use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadPart;

interface DirectUploadManager
{
    public function start(DirectUploadRequest $request): DirectUploadStartResult;

    public function refresh(object $session, array $partNumbers = []): \Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadProviderPlan;

    /** @return list<DirectUploadPart> */
    public function signParts(object $session, array $partNumbers): array;

    /** @param list<array{part_number:int,etag:string,checksum_sha256?:string|null}> $parts */
    public function finalize(object $session, array $parts = []): object;

    public function cancel(object $session): void;

    public function progress(object $session): array;

    public function cleanupExpired(int $limit = 100): array;
}
