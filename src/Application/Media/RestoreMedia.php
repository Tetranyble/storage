<?php

namespace Tetranyble\Storage\Application\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Tetranyble\Storage\Application\Support\WorkspaceResourceGuard;
use Tetranyble\Storage\Contracts\ActivityLogger;
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Domain\Media\MediaLibraryService;
use Tetranyble\Storage\Events\MediaRestored;
use Tetranyble\Storage\Models\Media;

class RestoreMedia
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly MediaLibraryService $library,
        private readonly ActivityLogger $activities,
        private readonly WorkspaceResourceGuard $resources,
    ) {}

    public function handle(Model $workspace, Media $media, ?Model $actor = null): Media
    {
        $media = $this->resources->media($workspace, $media, true);
        $this->access->authorizeEdit($workspace, $media, $actor);

        $this->library->restoreMedia($media);
        $restored = $media->refresh();
        Event::dispatch(new MediaRestored($restored, $actor));
        $this->activities->log(
            $restored,
            'storage.media.restored',
            'Media restored from trash.',
            $actor,
            workspaceId: (int) $workspace->getKey(),
        );

        return $restored;
    }
}
