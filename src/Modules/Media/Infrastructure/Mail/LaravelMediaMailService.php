<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Media\Infrastructure\Mail;

use Illuminate\Mail\Attachment;
use Tetranyble\Storage\Modules\Media\Application\DTO\MediaMailPayload;
use Tetranyble\Storage\Modules\Media\Application\MediaMailService;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;

/** Laravel mail adapter. The application core only produces transport-neutral payloads. */
class LaravelMediaMailService
{
    public function __construct(private readonly MediaMailService $mail) {}

    public function attachment(object $media): Attachment
    {
        $path = trim((string) ($media->path ?? ''));
        $disk = $media->disk ?? null;

        if ($disk instanceof Disk
            && $path !== ''
            && ! $this->isExternalPath($path)
            && ! in_array($disk, [Disk::YOUTUBE, Disk::VIMEO], true)) {
            $payload = $this->mail->signedLinkPayload($media);

            return Attachment::fromStorageDisk($disk->value, $path)
                ->as($payload->filename)
                ->withMime($payload->mime);
        }

        return $this->dataAttachment($media);
    }

    public function dataAttachment(object $media, ?int $maxSizeBytes = null): Attachment
    {
        $payload = $this->mail->base64Payload($media, $maxSizeBytes);

        return Attachment::fromData(
            static fn (): string => base64_decode((string) $payload->content, true) ?: '',
            $payload->filename,
        )->withMime($payload->mime);
    }

    public function base64Payload(object $media, ?int $maxSizeBytes = null): MediaMailPayload
    {
        return $this->mail->base64Payload($media, $maxSizeBytes);
    }

    public function signedLinkPayload(object $media, int $ttlMinutes = 60): MediaMailPayload
    {
        return $this->mail->signedLinkPayload($media, $ttlMinutes);
    }

    public function publicLinkPayload(
        object $workspace,
        object $media,
        string $accessLevel = 'download',
        ?int $ttlMinutes = 10080,
        ?int $maxDownloads = null,
        ?string $password = null,
        ?int $createdBy = null,
        bool $absolute = true,
    ): MediaMailPayload {
        return $this->mail->publicLinkPayload(
            $workspace,
            $media,
            $accessLevel,
            $ttlMinutes,
            $maxDownloads,
            $password,
            $createdBy,
            $absolute,
        );
    }

    private function isExternalPath(string $path): bool
    {
        return str_starts_with($path, '//') || filter_var($path, FILTER_VALIDATE_URL) !== false;
    }
}
