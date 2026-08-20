<?php

namespace Tetranyble\Storage\Domain\CloudDrive\Contracts;

use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;

/**
 * Adapters that implement this interface can perform copy/move entirely on the
 * provider side — no binary download/upload required.
 *
 * ConnectedDriveService checks for this capability when both source and target
 * are the same connected drive (same id), and falls back to download+upload
 * when the adapter does not implement it or drives differ.
 */
interface SupportsSameDriveOperations
{
    /**
     * Copy a file within the same drive without downloading the binary.
     *
     * @param  string  $fileId          Remote file ID on this drive.
     * @param  string  $targetFolderId  Destination folder ID (or 'root').
     * @param  string  $name            Name for the new copy.
     */
    public function copyFileSameDrive(string $fileId, string $targetFolderId, string $name): CloudFile;

    /**
     * Move a file within the same drive without downloading the binary.
     *
     * @param  string  $fileId          Remote file ID on this drive.
     * @param  string  $targetFolderId  Destination folder ID (or 'root').
     * @param  string  $name            New name (pass original name to keep it).
     */
    public function moveFileSameDrive(string $fileId, string $targetFolderId, string $name): CloudFile;
}
