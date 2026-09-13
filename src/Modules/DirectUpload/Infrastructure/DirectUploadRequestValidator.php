<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\DirectUpload\Infrastructure;

use Tetranyble\Storage\Modules\DirectUpload\Application\DTO\DirectUploadRequest;
use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\InvalidStorageOperationException;

final class DirectUploadRequestValidator
{
    public function assertValid(DirectUploadRequest $request): void
    {
        $max = max(1, (int) config('tetranyble-storage.uploads.max_size', 50 * 1024 * 1024));
        if ($request->expectedSize <= 0) {
            throw new InvalidStorageOperationException('Direct upload expected size must be greater than zero.');
        }
        if ($request->expectedSize > $max) {
            throw new InvalidStorageOperationException(sprintf(
                'Direct upload exceeds the configured maximum size (%d bytes > %d bytes).',
                $request->expectedSize,
                $max,
            ));
        }
        if ($request->expiresAt !== null && $request->expiresAt->getTimestamp() <= time()) {
            throw new InvalidStorageOperationException('Direct upload session expiry must be in the future.');
        }
        if ($request->sha256 !== null && preg_match('/^[a-f0-9]{64}$/i', $request->sha256) !== 1) {
            throw new InvalidStorageOperationException('Direct upload SHA-256 must be a 64-character hexadecimal digest.');
        }
        if ((bool) config('tetranyble-storage.direct_uploads.require_sha256', true) && ! $request->sha256) {
            throw new InvalidStorageOperationException('Direct uploads require a client-computed SHA-256 checksum.');
        }
    }
}
