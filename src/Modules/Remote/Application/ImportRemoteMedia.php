<?php

namespace Tetranyble\Storage\Modules\Remote\Application;

use InvalidArgumentException;
use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Application\Contracts\WorkspaceResourceLocator;
use Tetranyble\Storage\Modules\Remote\Application\Contracts\RemoteMediaImporter;
use Tetranyble\Storage\Modules\Remote\Application\Contracts\ResourceIdentity;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;

class ImportRemoteMedia
{
    public function __construct(
        private readonly RemoteMediaImporter $imports,
        private readonly ResourceAccessControl $access,
        private readonly WorkspaceResourceLocator $resources,
        private readonly ResourceIdentity $identity,
    ) {}

    public function handle(
        object $workspace,
        string $url,
        MediaUploadOptions $options,
        ?object $actor = null,
    ): object {
        if ($options->workspaceId !== null
            && (string) $options->workspaceId !== (string) $this->identity->key($workspace)) {
            throw new InvalidArgumentException('Import workspace does not match the application workspace.');
        }

        if ($options->userId !== null
            && (! $actor || (string) $options->userId !== (string) $this->identity->key($actor))) {
            throw new InvalidArgumentException('Import user does not match the application actor.');
        }

        if ($options->folderId !== null) {
            $folder = $this->resources->folderById($workspace, $options->folderId);
            if ($folder === null) {
                throw new \LogicException('Folder locator returned no resource.');
            }
            $this->access->authorizeEdit($workspace, $folder, $actor);
        }

        return $this->imports->uploadFromUrl($url, $options);
    }
}
