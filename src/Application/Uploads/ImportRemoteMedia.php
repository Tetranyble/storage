<?php

namespace Tetranyble\Storage\Application\Uploads;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Tetranyble\Storage\Application\Support\WorkspaceResourceGuard;
use Tetranyble\Storage\Contracts\RemoteMediaImporter;
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Models\Media;

class ImportRemoteMedia
{
    public function __construct(
        private readonly RemoteMediaImporter $imports,
        private readonly ResourceAccessControl $access,
        private readonly WorkspaceResourceGuard $resources,
    ) {}

    public function handle(
        Model $workspace,
        string $url,
        MediaUploadOptions $options,
        ?Model $actor = null,
    ): Media {
        if ($options->workspaceId !== null
            && (string) $options->workspaceId !== (string) $workspace->getKey()) {
            throw new InvalidArgumentException('Import workspace does not match the application workspace.');
        }

        if ($options->userId !== null
            && (! $actor || (string) $options->userId !== (string) $actor->getKey())) {
            throw new InvalidArgumentException('Import user does not match the application actor.');
        }

        if ($options->folderId !== null) {
            $folder = $this->resources->folderById($workspace, $options->folderId);
            $this->access->authorizeEdit($workspace, $folder, $actor);
        }

        return $this->imports->uploadFromUrl($url, $options);
    }
}
