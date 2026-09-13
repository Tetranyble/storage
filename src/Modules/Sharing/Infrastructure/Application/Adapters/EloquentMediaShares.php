<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Sharing\Infrastructure\Application\Adapters;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Sharing\Application\Contracts\MediaShares;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Application\MediaShareService;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Persistence\Eloquent\Models\MediaShare;

final class EloquentMediaShares implements MediaShares
{
    public function __construct(private readonly MediaShareService $shares) {}

    public function createForMedia(
        object $workspace,
        object $media,
        string $accessLevel = 'download',
        ?int $ttlMinutes = null,
        ?int $maxDownloads = null,
        ?string $password = null,
        ?int $createdBy = null,
    ): object {
        return $this->shares->createForMedia(
            $this->model($workspace),
            $this->media($media),
            $accessLevel,
            $ttlMinutes,
            $maxDownloads,
            $password,
            $createdBy,
        );
    }

    public function createForFolder(
        object $workspace,
        object $folder,
        string $accessLevel = 'view',
        ?int $ttlMinutes = null,
        ?int $maxDownloads = null,
        ?string $password = null,
        ?int $createdBy = null,
    ): object {
        return $this->shares->createForFolder(
            $this->model($workspace),
            $this->folder($folder),
            $accessLevel,
            $ttlMinutes,
            $maxDownloads,
            $password,
            $createdBy,
        );
    }

    public function resolveByToken(string $token): ?object
    {
        return $this->shares->resolveByToken($token);
    }

    public function validateAccess(object $share, ?string $password = null): void
    {
        $this->shares->validateAccess($this->share($share), $password);
    }

    public function validateDownloadAccess(object $share, ?string $password = null): void
    {
        $this->shares->validateDownloadAccess($this->share($share), $password);
    }

    public function consumeDownloadAccess(object $share, ?string $password = null): void
    {
        $this->shares->consumeDownloadAccess($this->share($share), $password);
    }

    public function urlFor(object $share, bool $absolute = true): string
    {
        return $this->shares->urlFor($this->share($share), $absolute);
    }

    private function model(object $value): Model
    {
        if (! $value instanceof Model) {
            throw new InvalidArgumentException('Expected Eloquent model.');
        }

        return $value;
    }

    private function media(object $value): Media
    {
        if (! $value instanceof Media) {
            throw new InvalidArgumentException('Expected Media model.');
        }

        return $value;
    }

    private function folder(object $value): Folder
    {
        if (! $value instanceof Folder) {
            throw new InvalidArgumentException('Expected Folder model.');
        }

        return $value;
    }

    private function share(object $value): MediaShare
    {
        if (! $value instanceof MediaShare) {
            throw new InvalidArgumentException('Expected MediaShare model.');
        }

        return $value;
    }
}
