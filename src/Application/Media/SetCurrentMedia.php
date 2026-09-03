<?php

namespace Tetranyble\Storage\Application\Media;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Application\Support\WorkspaceResourceGuard;
use Tetranyble\Storage\Contracts\ActivityLogger;
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Domain\Media\CurrentMediaSelectionService;
use Tetranyble\Storage\Models\Media;

class SetCurrentMedia
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly CurrentMediaSelectionService $currentSelection,
        private readonly ActivityLogger $activityLogger,
        private readonly WorkspaceResourceGuard $resources,
    ) {}

    public function handle(Model $workspace, Media $media, ?Model $actor = null): Media
    {
        $media = $this->resources->media($workspace, $media);
        abort_unless(
            $media->mediable_id !== null && $media->mediable_type !== null,
            422,
            'Standalone media has no model default.',
        );

        $this->access->authorizeEdit($workspace, $media, $actor);
        $selected = $this->currentSelection->select($media);

        $this->activityLogger->log(
            subject: $selected,
            type: 'storage.media.current.selected',
            description: 'Media selected as the current item.',
            actor: $actor,
            meta: ['purpose' => $selected->use?->value],
            workspaceId: $selected->workspace_id ? (int) $selected->workspace_id : null,
        );

        return $selected;
    }
}
