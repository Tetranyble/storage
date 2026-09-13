<?php

namespace Tetranyble\Storage\Modules\Sharing\Application;

use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Application\Contracts\WorkspaceResourceLocator;
use Tetranyble\Storage\Modules\Activity\Application\Contracts\ActivityLogger;
use Tetranyble\Storage\Modules\Shared\Application\Contracts\ResourceState;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;

final class RevokeMediaShare
{
    public function __construct(
        private readonly WorkspaceResourceLocator $resources,
        private readonly ResourceAccessControl $access,
        private readonly ActivityLogger $activity,
        private readonly ResourceState $state,
    ) {}

    public function handle(
        object $workspace,
        object $media,
        object $share,
        ?object $actor = null,
    ): void {
        $media = $this->resources->media($workspace, $media, allowTrashed: true);

        if ($actor) {
            $this->access->authorizeManagePermissions($workspace, $media, $actor);
        }

        if ((string) $this->state->attribute($share, 'workspace_id') !== (string) $this->state->key($workspace)
            || $this->state->attribute($share, 'shareable_type') !== $this->state->morphClass($media)
            || (string) $this->state->attribute($share, 'shareable_id') !== (string) $this->state->key($media)) {
            throw new ResourceNotFoundException;
        }

        $shareId = $this->state->key($share);
        $this->state->delete($share);

        $this->activity->log(
            subject: $media,
            type: 'storage.media.share_revoked',
            description: 'object share revoked.',
            actor: $actor,
            meta: ['share_id' => $shareId],
            workspaceId: (int) $this->state->key($workspace),
        );
    }
}
