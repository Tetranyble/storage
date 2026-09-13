<?php

namespace Tetranyble\Storage\Modules\DirectUpload\Application;

use Tetranyble\Storage\Modules\Shared\Application\Contracts\ResourceState;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;
use Tetranyble\Storage\Modules\Access\Application\Contracts\WorkspaceResourceLocator;
use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;

final class DirectUploadSessionGuard
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly WorkspaceResourceLocator $resources,
        private readonly ResourceState $state,
    ) {}

    public function authorize(object $workspace, object $session, ?object $actor = null): void
    {
        $workspaceId = $this->state->attribute($session, 'workspace_id');
        $userId = $this->state->attribute($session, 'user_id');
        if ((string) $workspaceId !== (string) $this->state->key($workspace)
            || ($userId !== null
                && (! $actor || (string) $userId !== (string) $this->state->key($actor)))) {
            throw new ResourceNotFoundException('Upload session was not found in the current workspace.');
        }

        $folderId = $this->state->attribute($session, 'folder_id');
        if ($folderId !== null) {
            $folder = $this->resources->folderById($workspace, (int) $folderId);
            if ($folder === null) {
                throw new \LogicException('Folder locator returned no resource.');
            }
            $this->access->authorizeEdit($workspace, $folder, $actor);
        }
    }
}
