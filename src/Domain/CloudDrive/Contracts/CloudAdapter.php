<?php

namespace Tetranyble\Storage\Domain\CloudDrive\Contracts;

use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;

interface CloudAdapter
{
    /**
     * List items inside a folder. Pass 'root' for the drive root.
     *
     * @return CloudFile[]
     */
    public function listFolder(string $folderId = 'root'): array;

    /** Download a file and return its binary content. */
    public function getFileBinary(string $fileId): string;

    /** Upload binary content to a folder. Returns metadata of the created file. */
    public function putFile(string $folderId, string $name, string $binary, string $mimeType = 'application/octet-stream'): CloudFile;

    /** Permanently delete a file or folder on the remote drive. */
    public function deleteFile(string $fileId): void;

    /** Create a folder inside a parent. */
    public function createFolder(string $parentId, string $name): CloudFile;

    /** Fetch metadata for a single item. */
    public function getMetadata(string $fileId): CloudFile;

    /**
     * Refresh the OAuth access token using the stored refresh token.
     *
     * @return array{access_token: string, expires_at: \DateTimeInterface}
     */
    public function refreshToken(): array;
}
