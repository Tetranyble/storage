<?php

namespace Tetranyble\Storage\Modules\Media\Application;

use InvalidArgumentException;
use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Application\Contracts\WorkspaceResourceLocator;
use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaLibrary;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Shared\Application\Contracts\ResourceState;
use Tetranyble\Storage\Modules\Shared\Application\Contracts\StorageEventPublisher;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\MediaUploader;
use Tetranyble\Storage\Modules\Storage\Application\DTO\IncomingFile;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\InvalidStorageOperationException;
use Tetranyble\Storage\Modules\Upload\Application\Contracts\UploadLimits;

class UploadMedia
{
    public function __construct(
        private readonly MediaUploader $uploader,
        private readonly MediaLibrary $library,
        private readonly ResourceAccessControl $access,
        private readonly WorkspaceResourceLocator $resources,
        private readonly StorageEventPublisher $events,
        private readonly UploadLimits $limits,
        private readonly ResourceState $state,
    ) {}

    public function handle(
        object $workspace,
        IncomingFile $file,
        MediaUploadOptions $options,
        ?object $actor = null,
    ): object {
        $this->assertContext($workspace, $actor, $options);
        $this->assertUploadSize($file);

        if ($options->folderId !== null) {
            $folder = $this->resources->folderById($workspace, $options->folderId);
            if ($folder === null) {
                throw new \LogicException('Folder locator returned no resource.');
            }
            $this->access->authorizeEdit($workspace, $folder, $actor);
        }

        return $this->uploader->uploadUploadedFile($file, $options);
    }

    public function uploadLibraryFiles(
        object $workspace,
        array $uploadedFiles,
        ?int $folderId = null,
        ?object $actor = null,
    ): array {
        $folder = $folderId !== null
            ? $this->resources->folderById($workspace, $folderId)
            : $this->library->createWorkspaceRoot($workspace);

        $this->access->authorizeEdit($workspace, $folder, $actor);

        $uploaded = [];
        foreach ($uploadedFiles as $file) {
            if (! $file instanceof IncomingFile) {
                throw new InvalidArgumentException('Library uploads must contain IncomingFile values.');
            }
            $this->assertUploadSize($file);

            $media = $this->uploader->uploadUploadedFile($file, MediaUploadOptions::forStandalone(
                workspaceId: (int) $this->state->key($workspace),
                userId: $actor ? (int) $this->state->key($actor) : null,
                folderId: (int) $this->state->key($folder),
                purpose: MediaPurpose::GENERAL,
                disk: Disk::PRIVATE,
                directory: 'workspace',
                module: 'file-centre',
                temporary: false,
                label: pathinfo($file->originalName, PATHINFO_FILENAME),
                attribution: 'workspace-file',
            ));

            $this->events->mediaUploaded($media, $actor);

            $uploaded[] = $media;
        }

        return $uploaded;
    }

    private function assertUploadSize(IncomingFile $file): void
    {
        $maxBytes = $this->limits->maxUploadBytes();
        $size = $file->size;

        if ($size > $maxBytes) {
            throw new InvalidStorageOperationException(sprintf(
                'Upload exceeds the configured maximum size (%d bytes > %d bytes).',
                $size,
                $maxBytes,
            ));
        }
    }

    private function assertContext(object $workspace, ?object $actor, MediaUploadOptions $options): void
    {
        if ($options->workspaceId !== null
            && (string) $options->workspaceId !== (string) $this->state->key($workspace)) {
            throw new InvalidArgumentException('Upload workspace does not match the application workspace.');
        }

        if ($options->userId !== null
            && (! $actor || (string) $options->userId !== (string) $this->state->key($actor))) {
            throw new InvalidArgumentException('Upload user does not match the application actor.');
        }
    }
}
