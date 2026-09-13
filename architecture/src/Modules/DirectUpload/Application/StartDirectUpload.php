<?php

namespace Tetranyble\Storage\Modules\DirectUpload\Application;

use Tetranyble\Storage\Modules\Shared\Application\Contracts\ResourceState;
use InvalidArgumentException;
use Tetranyble\Storage\Modules\Access\Application\Contracts\WorkspaceResourceLocator;
use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\DirectUpload\Application\Contracts\DirectUploadManager;
use Tetranyble\Storage\Modules\DirectUpload\Application\DTO\DirectUploadRequest;
use Tetranyble\Storage\Modules\DirectUpload\Application\DTO\DirectUploadStartResult;

final class StartDirectUpload
{
    public function __construct(
        private readonly DirectUploadManager $uploads,
        private readonly ResourceAccessControl $access,
        private readonly WorkspaceResourceLocator $resources,
        private readonly ResourceState $state,
    ) {}

    public function handle(object $workspace, DirectUploadRequest $request, ?object $actor = null): DirectUploadStartResult
    {
        $options = $request->upload;

        if ($options->workspaceId !== null
            && (string) $options->workspaceId !== (string) $this->state->key($workspace)) {
            throw new InvalidArgumentException('Upload workspace does not match the application workspace.');
        }

        if ($options->userId !== null
            && (! $actor || (string) $options->userId !== (string) $this->state->key($actor))) {
            throw new InvalidArgumentException('Upload user does not match the application actor.');
        }

        if ($options->folderId !== null) {
            $folder = $this->resources->folderById($workspace, $options->folderId);
            if ($folder === null) {
                throw new \LogicException('Folder locator returned no resource.');
            }
            $this->access->authorizeEdit($workspace, $folder, $actor);
        }

        return $this->uploads->start($request);
    }
}
