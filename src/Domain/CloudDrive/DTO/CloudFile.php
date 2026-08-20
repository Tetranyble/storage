<?php

namespace Tetranyble\Storage\Domain\CloudDrive\DTO;

use DateTimeInterface;

final readonly class CloudFile
{
    public function __construct(
        public string            $id,
        public string            $name,
        public bool              $isFolder,
        public ?int              $size,
        public ?string           $mimeType,
        public ?string           $webViewLink,
        public ?string           $thumbnailUrl,
        public ?DateTimeInterface $modifiedAt,
        public ?string           $parentId = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'is_folder'    => $this->isFolder,
            'size'         => $this->size,
            'mime_type'    => $this->mimeType,
            'web_view_link' => $this->webViewLink,
            'thumbnail_url' => $this->thumbnailUrl,
            'modified_at'  => $this->modifiedAt?->format('c'),
            'parent_id'    => $this->parentId,
        ];
    }
}
