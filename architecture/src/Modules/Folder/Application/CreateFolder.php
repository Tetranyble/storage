<?php

namespace Tetranyble\Storage\Modules\Folder\Application;

use Tetranyble\Storage\Modules\Shared\Application\Contracts\ResourceState;
use Tetranyble\Storage\Modules\Shared\Application\Contracts\StorageEventPublisher;
use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaLibrary;
use Tetranyble\Storage\Modules\Access\Application\Contracts\WorkspaceResourceLocator;
use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Domain\Enums\AccessScope;
use Tetranyble\Storage\Modules\Activity\Application\Contracts\ActivityLogger;

final class CreateFolder
{
    public function __construct(
        private readonly MediaLibrary $library,
        private readonly WorkspaceResourceLocator $resources,
        private readonly ResourceAccessControl $access,
        private readonly ActivityLogger $activity,
        private readonly StorageEventPublisher $events,
        private readonly ResourceState $state,
    ) {}

    public function handle(
        object $workspace,
        string $name,
        ?int $parentId = null,
        ?object $actor = null,
        ?AccessScope $scope = null,
    ): object {
        $parent = $parentId !== null
            ? $this->resources->folderById($workspace, $parentId)
            : $this->library->createWorkspaceRoot($workspace);

        
        if ($actor) {
            $this->access->authorizeEdit($workspace, $parent, $actor);
        }

        $folder = $this->library->createFolder($workspace, $name, $parent);
        $folder = $this->state->update($folder, [
            'created_by' => $actor ? $this->state->key($actor) : null,
            'access_scope' => $scope ?? $this->state->attribute($parent, 'access_scope', AccessScope::default()),
        ]);
        $this->activity->log(
            subject: $folder,
            type: 'storage.folder.created',
            description: 'object created.',
            actor: $actor,
            meta: ['parent_id' => $this->state->key($parent)],
            changes: ['after' => $this->snapshot($folder)],
            workspaceId: (int) $this->state->key($workspace),
        );
        $this->events->folderCreated($folder, $actor);

        return $folder;
    }

    private function snapshot(object $folder): array
    {
        return [
            'id' => $this->state->key($folder),
            'name' => $this->state->attribute($folder, 'name'),
            'path' => $this->state->attribute($folder, 'path'),
            'parent_id' => $this->state->attribute($folder, 'parent_id'),
            'access_scope' => $this->scopeValue($this->state->attribute($folder, 'access_scope')),
        ];
    }

    private function scopeValue(mixed $scope): string
    {
        return $scope instanceof AccessScope ? $scope->value : (is_string($scope) ? $scope : AccessScope::default()->value);
    }
}
