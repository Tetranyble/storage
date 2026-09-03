<?php

namespace Tetranyble\Storage\Application\Uploads;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Tetranyble\Storage\Application\Support\WorkspaceResourceGuard;
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Contracts\ResumableUploadManager;
use Tetranyble\Storage\Domain\FileSystem\DTO\UploadSessionOptions;
use Tetranyble\Storage\Models\UploadSession;

class StartResumableUpload
{
    public function __construct(
        private readonly ResumableUploadManager $uploads,
        private readonly ResourceAccessControl $access,
        private readonly WorkspaceResourceGuard $resources,
    ) {}

    public function handle(Model $workspace, UploadSessionOptions $options, ?Model $actor = null): UploadSession
    {
        $upload = $options->upload;

        if ($upload->workspaceId !== null
            && (string) $upload->workspaceId !== (string) $workspace->getKey()) {
            throw new InvalidArgumentException('Upload workspace does not match the application workspace.');
        }

        if ($upload->userId !== null
            && (! $actor || (string) $upload->userId !== (string) $actor->getKey())) {
            throw new InvalidArgumentException('Upload user does not match the application actor.');
        }

        if ($upload->folderId !== null) {
            $folder = $this->resources->folderById($workspace, $upload->folderId);
            $this->access->authorizeEdit($workspace, $folder, $actor);
        }

        return $this->uploads->startSession($options);
    }
}
