<?php

namespace Tetranyble\Storage\Modules\Download\Infrastructure\Application;

use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\ConnectedDriveService;
use Tetranyble\Storage\Modules\Processing\Application\MediaDeliveryGuard;

use Tetranyble\Storage\Modules\Access\Domain\Exceptions\AuthenticationRequiredException;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;
use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Contracts\CloudAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Domain\DTO\DownloadPayload;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Access\Domain\Enums\AccessScope;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Authorised single-file and bulk zip downloads.
 *
 * WORKSPACE-scoped media: any authenticated actor belonging to the same workspace may
 * download. RESTRICTED-scoped media: actor must hold at least VIEW permission via
 * the ResourceAccessControl contract.
 *
 * For cloud-drive files there is no ACL layer inside this package — the caller
 * is responsible for verifying that the user has access to the connected drive
 * before invoking these methods.
 */
class DownloadService
{
    public function __construct(
        private readonly FileSystemContract    $files,
        private readonly ConnectedDriveService $drives,
        private readonly ResourceAccessControl $access,
        private readonly ?MediaDeliveryGuard $delivery = null,
    ) {}

    // ---------------------------------------------------------------
    // Local Media
    // ---------------------------------------------------------------

    /**
     * Read and return a single local Media file as a download response.
     * Throws package authorization exceptions if the actor lacks VIEW permission.
     */
    public function downloadMedia(Model $workspace, Media $media, ?Model $actor = null): DownloadPayload
    {
        $this->authorizeMedia($workspace, $media, $actor);
        $this->assertDeliverable($media);

        $binary   = $this->files->get((string) $media->path, $media->disk);
        $filename = $media->original_name ?? basename((string) $media->path);
        $mime     = $media->mime_type ?? 'application/octet-stream';

        return new DownloadPayload($binary, $filename, $mime);
    }

    /**
     * Build a ZIP archive from multiple local Media records and stream it.
     * Items the actor cannot view are silently skipped.
     *
     * @param  Media[]  $mediaItems
     * @return array{download: DownloadPayload, zipped: int, skipped: int}
     */
    public function zipMedia(
        Model   $workspace,
        array   $mediaItems,
        ?Model  $actor = null,
        string  $archiveName = 'download',
    ): array {
        $this->requireZipArchive();

        $tmpPath = sys_get_temp_dir().'/'.Str::uuid().'.zip';
        $zip     = $this->openZip($tmpPath);
        $zipped  = 0;
        $skipped = 0;

        foreach ($mediaItems as $media) {
            if (! $this->canViewMedia($workspace, $media, $actor)
                || ! $this->canDeliver($media)) {
                $skipped++;
                continue;
            }

            try {
                $binary   = $this->files->get((string) $media->path, $media->disk);
                $filename = $this->uniqueFilename($zip, $media->original_name ?? basename((string) $media->path));
                $zip->addFromString($filename, $binary);
                $zipped++;
            } catch (\Throwable) {
                $skipped++;
            }
        }

        $zip->close();
        $download = $this->zipPayload($tmpPath, $archiveName.'.zip');
        @unlink($tmpPath);

        return ['download' => $download, 'zipped' => $zipped, 'skipped' => $skipped];
    }

    // ---------------------------------------------------------------
    // Remote cloud-drive files
    // ---------------------------------------------------------------

    /**
     * Stream a single remote file from a connected drive.
     */
    public function downloadFromDrive(
        Model          $workspace,
        ConnectedDrive $drive,
        string         $remoteFileId,
    ): DownloadPayload {
        $adapter  = $this->drives->adapterFor($drive);
        $meta     = $adapter->getMetadata($remoteFileId);
        $binary   = $adapter->getFileBinary($remoteFileId);

        return new DownloadPayload($binary, $meta->name, $meta->mimeType ?? 'application/octet-stream');
    }

    /**
     * Zip multiple remote files/folders from a connected drive and stream the archive.
     * Folders are recursively included. Items that fail to download are skipped.
     *
     * @param  string[]  $remoteFileIds
     * @return array{download: DownloadPayload, zipped: int, skipped: int}
     */
    public function zipFromDrive(
        Model          $workspace,
        ConnectedDrive $drive,
        array          $remoteFileIds,
        string         $archiveName = 'download',
    ): array {
        $this->requireZipArchive();

        $tmpPath = sys_get_temp_dir().'/'.Str::uuid().'.zip';
        $zip     = $this->openZip($tmpPath);
        $adapter = $this->drives->adapterFor($drive);
        $zipped  = 0;
        $skipped = 0;

        foreach ($remoteFileIds as $fileId) {
            try {
                $meta = $adapter->getMetadata($fileId);

                if ($meta->isFolder) {
                    $added = $this->addFolderToZip($zip, $adapter, $fileId, $meta->name);
                    $zipped += $added;
                } else {
                    $binary   = $adapter->getFileBinary($fileId);
                    $filename = $this->uniqueFilename($zip, $meta->name);
                    $zip->addFromString($filename, $binary);
                    $zipped++;
                }
            } catch (\Throwable) {
                $skipped++;
            }
        }

        $zip->close();
        $download = $this->zipPayload($tmpPath, $archiveName.'.zip');
        @unlink($tmpPath);

        return ['download' => $download, 'zipped' => $zipped, 'skipped' => $skipped];
    }

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    private function authorizeMedia(Model $workspace, Media $media, ?Model $actor): void
    {
        if ((int) ($media->workspace_id ?? 0) !== (int) $workspace->id) {
            throw new ResourceNotFoundException();
        }

        // WORKSPACE-scoped: any authenticated workspace member may download
        if ($media->access_scope === AccessScope::WORKSPACE) {
            if (! $actor) {
                throw new AuthenticationRequiredException();
            }
            return;
        }

        // RESTRICTED: explicit ACL check required
        if (! $actor) {
            throw new AuthenticationRequiredException();
        }

        $this->access->authorizeView($workspace, $media, $actor);
    }

    private function canViewMedia(Model $workspace, Media $media, ?Model $actor): bool
    {
        if ((int) ($media->workspace_id ?? 0) !== (int) $workspace->id) {
            return false;
        }

        if ($media->access_scope === AccessScope::WORKSPACE) {
            return $actor !== null;
        }

        return $actor && $this->access->canView($workspace, $media, $actor);
    }

    private function assertDeliverable(Media $media): void
    {
        if ($this->delivery) {
            $this->delivery->assertDeliverable($media);
            return;
        }

        if ((bool) config('tetranyble-storage.trust.virus_scanning.enabled', false)) {
            throw new RuntimeException(
                'DownloadService must be resolved through the Laravel container when virus scanning is enabled.'
            );
        }
    }

    private function canDeliver(Media $media): bool
    {
        if ($this->delivery) {
            return $this->delivery->canDeliver($media);
        }

        return ! (bool) config('tetranyble-storage.trust.virus_scanning.enabled', false);
    }

    /**
     * Recursively add all files under a remote folder to the archive.
     * Returns the number of files successfully added.
     */
    private function addFolderToZip(ZipArchive $zip, CloudAdapter $adapter, string $folderId, string $zipPrefix): int
    {
        $items = $adapter->listFolder($folderId);
        $added = 0;

        foreach ($items as $item) {
            if ($item->isFolder) {
                $added += $this->addFolderToZip($zip, $adapter, $item->id, $zipPrefix.'/'.$item->name);
            } else {
                try {
                    $binary   = $adapter->getFileBinary($item->id);
                    $filename = $this->uniqueFilename($zip, $zipPrefix.'/'.$item->name);
                    $zip->addFromString($filename, $binary);
                    $added++;
                } catch (\Throwable) {
                    // skip unreadable files silently
                }
            }
        }

        return $added;
    }

    private function zipPayload(string $tmpPath, string $filename): DownloadPayload
    {
        // ZipArchive::close() does not write the file when the archive is empty.
        $binary = is_file($tmpPath) ? (string) file_get_contents($tmpPath) : '';

        return new DownloadPayload($binary, $filename, 'application/zip');
    }

    private function openZip(string $tmpPath): ZipArchive
    {
        $zip = new ZipArchive();

        if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create temporary zip archive.');
        }

        return $zip;
    }

    private function requireZipArchive(): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP ZipArchive extension is required for zip downloads.');
        }
    }

    /**
     * Return a unique entry name inside the archive by appending (N) if needed.
     */
    private function uniqueFilename(ZipArchive $zip, string $name): string
    {
        if ($zip->locateName($name) === false) {
            return $name;
        }

        $ext    = pathinfo($name, PATHINFO_EXTENSION);
        $base   = $ext ? substr($name, 0, -(strlen($ext) + 1)) : $name;
        $suffix = 1;

        do {
            $candidate = $ext ? "{$base} ({$suffix}).{$ext}" : "{$base} ({$suffix})";
            $suffix++;
        } while ($zip->locateName($candidate) !== false);

        return $candidate;
    }
}
