<?php

namespace Tetranyble\Storage\Modules\Media\Application;

use RuntimeException;
use Tetranyble\Storage\Modules\Media\Application\DTO\MediaMailPayload;
use Tetranyble\Storage\Modules\Processing\Application\MediaDeliveryGuard;
use Tetranyble\Storage\Modules\Shared\Application\Contracts\ResourceState;
use Tetranyble\Storage\Modules\Sharing\Application\Contracts\MediaShares;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;

class MediaMailService
{
    public function __construct(
        private readonly FileSystemContract $files,
        private readonly MediaShares $shares,
        private readonly MediaDeliveryGuard $delivery,
        private readonly ResourceState $state,
    ) {}

    public function base64Payload(object $media, ?int $maxSizeBytes = null): MediaMailPayload
    {
        $this->delivery->assertDeliverable($media);
        $path = $this->path($media);
        $disk = $this->disk($media);

        return MediaMailPayload::base64(
            filename: $this->filename($media),
            mime: $this->mime($media),
            content: base64_encode($this->files->get($path, $disk, $maxSizeBytes)),
        );
    }

    public function signedLinkPayload(object $media, int $ttlMinutes = 60): MediaMailPayload
    {
        $this->delivery->assertDeliverable($media);

        return MediaMailPayload::url(
            filename: $this->filename($media),
            mime: $this->mime($media),
            url: $this->files->signedUrl($this->path($media), $this->disk($media), $ttlMinutes),
        );
    }

    public function publicLinkPayload(
        object $workspace,
        object $media,
        string $accessLevel = 'download',
        ?int $ttlMinutes = 60 * 24 * 7,
        ?int $maxDownloads = null,
        ?string $password = null,
        ?int $createdBy = null,
        bool $absolute = true,
    ): MediaMailPayload {
        $this->delivery->assertDeliverable($media);

        $share = $this->shares->createForMedia(
            workspace: $workspace,
            media: $media,
            accessLevel: $accessLevel,
            ttlMinutes: $ttlMinutes,
            maxDownloads: $maxDownloads,
            password: $password,
            createdBy: $createdBy,
        );

        return MediaMailPayload::url(
            filename: $this->filename($media),
            mime: $this->mime($media),
            url: $this->shares->urlFor($share, $absolute),
            share: $share,
        );
    }

    private function filename(object $media): string
    {
        $filename = trim((string) $this->state->attribute($media, 'original_name', ''));
        if ($filename !== '') {
            return $filename;
        }

        $path = trim((string) $this->state->attribute($media, 'path', ''));
        if ($path !== '') {
            $basename = basename(parse_url($path, PHP_URL_PATH) ?: $path);
            if ($basename !== '' && $basename !== '.' && $basename !== '/') {
                return $basename;
            }
        }

        return 'media-'.$this->state->key($media);
    }

    private function mime(object $media): string
    {
        $mime = trim((string) $this->state->attribute($media, 'mime_type', ''));
        if ($mime !== '') {
            return $mime;
        }

        $path = trim((string) $this->state->attribute($media, 'path', ''));
        $disk = $this->disk($media);

        return $path !== ''
            ? ($this->files->mimeType($path, $disk) ?: 'application/octet-stream')
            : 'application/octet-stream';
    }

    private function path(object $media): string
    {
        $path = trim((string) $this->state->attribute($media, 'path', ''));
        if ($path === '') {
            throw new RuntimeException('Cannot prepare an email attachment for media without a path.');
        }

        return $path;
    }

    private function disk(object $media): Disk
    {
        $disk = $this->state->attribute($media, 'disk');
        if ($disk instanceof Disk) {
            return $disk;
        }

        return is_string($disk) ? (Disk::tryFrom($disk) ?? Disk::PUBLIC) : Disk::PUBLIC;
    }
}
