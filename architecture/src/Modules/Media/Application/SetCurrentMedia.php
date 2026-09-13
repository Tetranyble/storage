<?php

namespace Tetranyble\Storage\Modules\Media\Application;

use Tetranyble\Storage\Modules\Shared\Application\Contracts\ResourceState;
use Tetranyble\Storage\Modules\Access\Application\Contracts\WorkspaceResourceLocator;
use Tetranyble\Storage\Modules\Activity\Application\Contracts\ActivityLogger;
use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\InvalidStorageOperationException;
use Tetranyble\Storage\Modules\Versioning\Application\Contracts\CurrentMediaSelection;

class SetCurrentMedia
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly CurrentMediaSelection $currentSelection,
        private readonly ActivityLogger $activityLogger,
        private readonly WorkspaceResourceLocator $resources,
        private readonly ResourceState $state,
    ) {}

    public function handle(object $workspace, object $media, ?object $actor = null): object
    {
        $media = $this->resources->media($workspace, $media);
        if ($this->state->attribute($media, 'mediable_id') === null
            || $this->state->attribute($media, 'mediable_type') === null) {
            throw new InvalidStorageOperationException('Standalone media has no model default.');
        }

        $this->access->authorizeEdit($workspace, $media, $actor);
        $selected = $this->currentSelection->select($media);
        
        $this->activityLogger->log(
            subject: $selected,
            type: 'storage.media.current.selected',
            description: 'object selected as the current item.',
            actor: $actor,
            meta: ['purpose' => $this->enumValue($this->state->attribute($selected, 'use'))],
            workspaceId: ($workspaceId = $this->state->attribute($selected, 'workspace_id')) !== null ? (int) $workspaceId : null,
        );

        return $selected;
    }

    private function enumValue(mixed $value): mixed
    {
        return is_object($value) && property_exists($value, 'value') ? $value->value : $value;
    }
}
