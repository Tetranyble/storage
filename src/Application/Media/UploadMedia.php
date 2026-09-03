<?php

namespace Tetranyble\Storage\Application\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tetranyble\Storage\Application\Support\WorkspaceResourceGuard;
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Domain\FileSystem\Contracts\MediaUploader;
use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\Media\MediaLibraryService;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Events\MediaUploaded;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;

class UploadMedia
{
    public function __construct(
        private readonly MediaUploader $uploader,
        private readonly MediaLibraryService $library,
        private readonly ResourceAccessControl $access,
        private readonly WorkspaceResourceGuard $resources,
    ) {}

    public function handle(
        Model $workspace,
        UploadedFile $file,
        MediaUploadOptions $options,
        ?Model $actor = null,
    ): Media {
        $this->assertContext($workspace, $actor, $options);

        if ($options->folderId !== null) {
            $folder = $this->resources->folderById($workspace, $options->folderId);
            $this->access->authorizeEdit($workspace, $folder, $actor);
        }

        return $this->uploader->uploadUploadedFile($file, $options);
    }

    public function uploadLibraryFiles(
        Model $workspace,
        array $uploadedFiles,
        ?int $folderId = null,
        ?Model $actor = null,
    ): Collection {
        $folder = $folderId !== null
            ? $this->resources->folderById($workspace, $folderId)
            : $this->library->createWorkspaceRoot($workspace);

        $this->access->authorizeEdit($workspace, $folder, $actor);

        return collect($uploadedFiles)->map(function (UploadedFile $file) use ($workspace, $folder, $actor): Media {
            $media = $this->uploader->uploadUploadedFile($file, MediaUploadOptions::forStandalone(
                workspaceId: (int) $workspace->getKey(),
                userId: $actor ? (int) $actor->getKey() : null,
                folderId: (int) $folder->getKey(),
                purpose: MediaPurpose::GENERAL,
                disk: Disk::PRIVATE,
                directory: 'workspace',
                module: 'file-centre',
                temporary: false,
                label: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                attribution: 'workspace-file',
            ));

            Event::dispatch(new MediaUploaded($media, $actor));

            return $media;
        });
    }

    private function assertContext(Model $workspace, ?Model $actor, MediaUploadOptions $options): void
    {
        if ($options->workspaceId !== null
            && (string) $options->workspaceId !== (string) $workspace->getKey()) {
            throw new InvalidArgumentException('Upload workspace does not match the application workspace.');
        }

        if ($options->userId !== null
            && (! $actor || (string) $options->userId !== (string) $actor->getKey())) {
            throw new InvalidArgumentException('Upload user does not match the application actor.');
        }
    }
}
