<?php

namespace Tetranyble\Storage\Modules\Sharing\Application;

use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Application\Contracts\WorkspaceResourceLocator;
use Tetranyble\Storage\Modules\Activity\Application\Contracts\ActivityLogger;
use Tetranyble\Storage\Modules\Shared\Application\Contracts\ResourceState;
use Tetranyble\Storage\Modules\Shared\Application\Contracts\StorageEventPublisher;
use Tetranyble\Storage\Modules\Sharing\Application\Contracts\MediaShares;

final class CreateMediaShare
{
    public function __construct(
        private readonly WorkspaceResourceLocator $resources,
        private readonly ResourceAccessControl $access,
        private readonly MediaShares $shares,
        private readonly ActivityLogger $activity,
        private readonly StorageEventPublisher $events,
        private readonly ResourceState $state,
    ) {}

    public function handle(
        object $workspace,
        object $media,
        ?object $user,
        string $accessLevel = 'download',
        ?int $ttlMinutes = null,
        ?int $maxDownloads = null,
        ?string $password = null,
        ?object $actor = null,
    ): object {
        $media = $this->resources->media($workspace, $media);

        if ($actor) {
            $this->access->authorizeManagePermissions($workspace, $media, $actor);
        }

        $share = $this->shares->createForMedia(
            workspace: $workspace,
            media: $media,
            accessLevel: $accessLevel,
            ttlMinutes: $ttlMinutes,
            maxDownloads: $maxDownloads,
            password: $password,
            createdBy: $actor ? $this->state->key($actor) : ($user ? $this->state->key($user) : null),
        );

        $this->activity->log(
            subject: $media,
            type: 'storage.media.shared',
            description: 'object share created.',
            actor: $actor ?? $user,
            meta: [
                'share_id' => $this->state->key($share),
                'access_level' => $this->state->attribute($share, 'access_level'),
                'expires_at' => $this->state->attribute($share, 'expires_at'),
                'max_downloads' => $this->state->attribute($share, 'max_downloads'),
                'requires_password' => $this->state->attribute($share, 'requires_password'),
            ],
            workspaceId: (int) $this->state->key($workspace),
        );

        $this->events->mediaShared($media, $share, $actor ?? $user);

        return $share;
    }
}
