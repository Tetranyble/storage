<?php

namespace Tetranyble\Storage\Facades;

use Tetranyble\Storage\Domain\Media\DTO\MediaMailPayload;
use Tetranyble\Storage\Domain\Media\MediaMailService;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\Workspace;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Attachment       attachment(Media $media)
 * @method static Attachment       dataAttachment(Media $media, ?int $maxSizeBytes = null)
 * @method static MediaMailPayload base64Payload(Media $media, ?int $maxSizeBytes = null)
 * @method static MediaMailPayload signedLinkPayload(Media $media, int $ttlMinutes = 60)
 * @method static MediaMailPayload publicLinkPayload(Workspace $workspace, Media $media, string $accessLevel = 'download', ?int $ttlMinutes = null, ?int $maxDownloads = null, ?string $password = null, ?int $createdBy = null, bool $absolute = true)
 *
 * @see MediaMailService
 */
class MediaMail extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MediaMailService::class;
    }
}
