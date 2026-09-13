<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Infrastructure\Laravel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Tetranyble\Storage\Events\FolderCreated;
use Tetranyble\Storage\Events\MediaPermanentlyDeleted;
use Tetranyble\Storage\Events\MediaRestored;
use Tetranyble\Storage\Events\MediaShared;
use Tetranyble\Storage\Events\MediaTrashed;
use Tetranyble\Storage\Events\MediaUploaded;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Shared\Application\Contracts\StorageEventPublisher;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Persistence\Eloquent\Models\MediaShare;

final class LaravelStorageEventPublisher implements StorageEventPublisher
{
    public function folderCreated(object $folder, ?object $actor = null): void
    {
        Event::dispatch(new FolderCreated($this->folder($folder), $this->actor($actor)));
    }

    public function mediaUploaded(object $media, ?object $actor = null): void
    {
        Event::dispatch(new MediaUploaded($this->media($media), $this->actor($actor)));
    }

    public function mediaTrashed(object $media, ?object $actor = null): void
    {
        Event::dispatch(new MediaTrashed($this->media($media), $this->actor($actor)));
    }

    public function mediaRestored(object $media, ?object $actor = null): void
    {
        Event::dispatch(new MediaRestored($this->media($media), $this->actor($actor)));
    }

    public function mediaPermanentlyDeleted(int $mediaId, ?int $workspaceId, ?string $path, ?object $actor = null): void
    {
        Event::dispatch(new MediaPermanentlyDeleted($mediaId, $workspaceId, $path, $this->actor($actor)));
    }

    public function mediaShared(object $media, object $share, ?object $actor = null): void
    {
        Event::dispatch(new MediaShared($this->media($media), $this->share($share), $this->actor($actor)));
    }

    private function folder(object $value): Folder
    {
        if (! $value instanceof Folder) {
            throw new \InvalidArgumentException('Expected Folder model.');
        }

return $value;
    }

    private function media(object $value): Media
    {
        if (! $value instanceof Media) {
            throw new \InvalidArgumentException('Expected Media model.');
        }

return $value;
    }

    private function share(object $value): MediaShare
    {
        if (! $value instanceof MediaShare) {
            throw new \InvalidArgumentException('Expected MediaShare model.');
        }

return $value;
    }

    private function actor(?object $value): ?Model
    {
        if ($value === null) {
            return null;
        } if (! $value instanceof Model) {
            throw new \InvalidArgumentException('Expected Eloquent actor model.');
        }

return $value;
    }
}
