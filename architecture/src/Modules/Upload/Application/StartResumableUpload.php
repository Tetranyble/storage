<?php

namespace Tetranyble\Storage\Modules\Upload\Application;

use InvalidArgumentException;
use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Application\Contracts\WorkspaceResourceLocator;
use Tetranyble\Storage\Modules\Shared\Application\Contracts\ResourceState;
use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\InvalidStorageOperationException;
use Tetranyble\Storage\Modules\Upload\Application\Contracts\ResumableUploadManager;
use Tetranyble\Storage\Modules\Upload\Application\Contracts\UploadLimits;
use Tetranyble\Storage\Modules\Upload\Application\DTO\UploadSessionOptions;

class StartResumableUpload
{
    public function __construct(
        private readonly ResumableUploadManager $uploads,
        private readonly ResourceAccessControl $access,
        private readonly WorkspaceResourceLocator $resources,
        private readonly UploadLimits $limits,
        private readonly ResourceState $state,
    ) {}

    public function handle(object $workspace, UploadSessionOptions $options, ?object $actor = null): object
    {
        $upload = $options->upload;
        $maxBytes = $this->limits->maxUploadBytes();

        if ($options->totalSize !== null && $options->totalSize > $maxBytes) {
            throw new InvalidStorageOperationException(sprintf(
                'Upload session exceeds the configured maximum size (%d bytes > %d bytes).',
                $options->totalSize,
                $maxBytes,
            ));
        }

        if ($upload->workspaceId !== null
            && (string) $upload->workspaceId !== (string) $this->state->key($workspace)) {
            throw new InvalidArgumentException('Upload workspace does not match the application workspace.');
        }

        if ($upload->userId !== null
            && (! $actor || (string) $upload->userId !== (string) $this->state->key($actor))) {
            throw new InvalidArgumentException('Upload user does not match the application actor.');
        }

        if ($upload->folderId !== null) {
            $folder = $this->resources->folderById($workspace, $upload->folderId);
            if ($folder === null) {
                throw new \LogicException('Folder locator returned no resource.');
            }
            $this->access->authorizeEdit($workspace, $folder, $actor);
        }

        return $this->uploads->startSession($options);
    }
}
