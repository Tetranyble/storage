<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Sharing\Domain\Policy;

use DateTimeImmutable;
use Tetranyble\Storage\Modules\Sharing\Domain\Enums\ShareAccessLevel;
use Tetranyble\Storage\Modules\Sharing\Domain\Exceptions\ShareDownloadLimitReachedException;
use Tetranyble\Storage\Modules\Sharing\Domain\Exceptions\ShareDownloadNotAllowedException;
use Tetranyble\Storage\Modules\Sharing\Domain\Exceptions\ShareExpiredException;

final readonly class ShareAccessPolicy
{
    public function __construct(
        public ShareAccessLevel $accessLevel,
        public ?DateTimeImmutable $expiresAt,
        public ?int $maxDownloads,
        public int $downloadsCount,
        public bool $requiresPassword,
    ) {
        if ($maxDownloads !== null && $maxDownloads < 0) {
            throw new \InvalidArgumentException('Maximum downloads cannot be negative.');
        }
        if ($downloadsCount < 0) {
            throw new \InvalidArgumentException('Download count cannot be negative.');
        }
    }

    public function assertAccessible(DateTimeImmutable $now): void
    {
        if ($this->expiresAt !== null && $now > $this->expiresAt) {
            throw new ShareExpiredException;
        }

        if ($this->maxDownloads !== null && $this->downloadsCount >= $this->maxDownloads) {
            throw new ShareDownloadLimitReachedException;
        }
    }

    public function assertDownloadAllowed(DateTimeImmutable $now): void
    {
        $this->assertAccessible($now);

        if ($this->accessLevel !== ShareAccessLevel::DOWNLOAD) {
            throw new ShareDownloadNotAllowedException;
        }
    }
}
