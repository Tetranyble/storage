<?php

namespace Tetranyble\Storage\Domain\Media;

use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\Media\DTO\MediaMailPayload;
use Tetranyble\Storage\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Attachment as MailAttachment;
use RuntimeException;

class MediaMailService
{
    public function __construct(
        private readonly FileSystemContract $files,
        private readonly MediaShareService $shares,
    ) {}

    public function attachment(Media $media): MailAttachment
    {
        if ($this->canAttachFromStorage($media)) {
            return MailAttachment::fromStorageDisk($media->disk->value, $this->path($media))
                ->as($this->filename($media))
                ->withMime($this->mime($media));
        }

        return $this->dataAttachment($media);
    }

    public function dataAttachment(Media $media, ?int $maxSizeBytes = null): MailAttachment
    {
        $path = $this->path($media);
        $disk = $media->disk ?? Disk::PUBLIC;

        return MailAttachment::fromData(
            fn () => $this->files->get($path, $disk, $maxSizeBytes),
            $this->filename($media),
        )->withMime($this->mime($media));
    }

    public function base64Payload(Media $media, ?int $maxSizeBytes = null): MediaMailPayload
    {
        $path = $this->path($media);
        $disk = $media->disk ?? Disk::PUBLIC;

        return MediaMailPayload::base64(
            filename: $this->filename($media),
            mime: $this->mime($media),
            content: base64_encode($this->files->get($path, $disk, $maxSizeBytes)),
        );
    }

    public function signedLinkPayload(Media $media, int $ttlMinutes = 60): MediaMailPayload
    {
        return MediaMailPayload::url(
            filename: $this->filename($media),
            mime: $this->mime($media),
            url: $this->files->signedUrl($this->path($media), $media->disk ?? Disk::PUBLIC, $ttlMinutes),
        );
    }

    public function publicLinkPayload(
        Model $workspace,
        Media $media,
        string $accessLevel = 'download',
        ?int $ttlMinutes = 60 * 24 * 7,
        ?int $maxDownloads = null,
        ?string $password = null,
        ?int $createdBy = null,
        bool $absolute = true,
    ): MediaMailPayload {
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

    private function filename(Media $media): string
    {
        $filename = trim((string) ($media->original_name ?? ''));
        if ($filename !== '') {
            return $filename;
        }

        $path = trim((string) ($media->path ?? ''));
        if ($path !== '') {
            $basename = basename(parse_url($path, PHP_URL_PATH) ?: $path);
            if ($basename !== '' && $basename !== '.' && $basename !== '/') {
                return $basename;
            }
        }

        return 'media-'.$media->id;
    }

    private function mime(Media $media): string
    {
        $mime = trim((string) ($media->mime_type ?? ''));
        if ($mime !== '') {
            return $mime;
        }

        $path = trim((string) ($media->path ?? ''));
        $disk = $media->disk ?? Disk::PUBLIC;

        return $path !== ''
            ? ($this->files->mimeType($path, $disk) ?: 'application/octet-stream')
            : 'application/octet-stream';
    }

    private function path(Media $media): string
    {
        $path = trim((string) ($media->path ?? ''));
        if ($path === '') {
            throw new RuntimeException('Cannot prepare an email attachment for media without a path.');
        }

        return $path;
    }

    private function canAttachFromStorage(Media $media): bool
    {
        $path = $this->path($media);

        return $media->disk instanceof Disk
            && ! $this->isExternalPath($path)
            && ! in_array($media->disk, [Disk::YOUTUBE, Disk::VIMEO], true);
    }

    private function isExternalPath(string $path): bool
    {
        if (str_starts_with($path, '//')) {
            return true;
        }

        return filter_var($path, FILTER_VALIDATE_URL) !== false;
    }
}
