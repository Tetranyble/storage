<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Handlers;

use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Activity\Infrastructure\Persistence\Eloquent\Models\Activity;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Versioning\Infrastructure\Application\MediaVersioningService;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\MediaVersions;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\EloquentReadResources;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\WorkspaceReadProjector;

final class MediaVersionsHandler
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly MediaVersioningService $versioning,
        private readonly WorkspaceReadProjector $projector,
        private readonly EloquentReadResources $resources,
    ) {}

    public function handle(MediaVersions $query): array
    {
        $workspace = $this->resources->model($query->workspace, 'workspace');
        $input = $this->resources->media($query->media);
        $media = Media::withTrashed()
            ->where('workspace_id', $workspace->id)
            ->whereKey($input->getKey())
            ->firstOrFail();
        $actor = $query->actor ? $this->resources->model($query->actor, 'actor') : null;

        if ($actor) {
            $this->access->authorizeView($workspace, $media, $actor);
        }

        return [
            'media_id' => $media->id,
            'versions' => $this->versioning->versions($media)
                ->map(fn (Media $version) => $this->projector->file($version))
                ->values(),
            'history' => $this->versioning->activity($media)
                ->map(fn (Activity $activity) => $this->projector->activity($activity))
                ->values(),
        ];
    }
}
