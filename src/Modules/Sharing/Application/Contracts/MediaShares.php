<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Sharing\Application\Contracts;

interface MediaShares
{
    public function createForMedia(
        object $workspace,
        object $media,
        string $accessLevel = 'download',
        ?int $ttlMinutes = null,
        ?int $maxDownloads = null,
        ?string $password = null,
        ?int $createdBy = null,
    ): object;

    public function createForFolder(
        object $workspace,
        object $folder,
        string $accessLevel = 'view',
        ?int $ttlMinutes = null,
        ?int $maxDownloads = null,
        ?string $password = null,
        ?int $createdBy = null,
    ): object;

    public function resolveByToken(string $token): ?object;

    public function validateAccess(object $share, ?string $password = null): void;

    public function validateDownloadAccess(object $share, ?string $password = null): void;

    public function consumeDownloadAccess(object $share, ?string $password = null): void;

    public function urlFor(object $share, bool $absolute = true): string;
}
