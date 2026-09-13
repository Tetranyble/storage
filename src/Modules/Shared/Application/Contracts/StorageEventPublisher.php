<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Shared\Application\Contracts;

/** Publishes package lifecycle notifications without coupling use cases to Laravel's event bus. */
interface StorageEventPublisher
{
    public function folderCreated(object $folder, ?object $actor = null): void;

    public function mediaUploaded(object $media, ?object $actor = null): void;

    public function mediaTrashed(object $media, ?object $actor = null): void;

    public function mediaRestored(object $media, ?object $actor = null): void;

    public function mediaPermanentlyDeleted(int $mediaId, ?int $workspaceId, ?string $path, ?object $actor = null): void;

    public function mediaShared(object $media, object $share, ?object $actor = null): void;
}
