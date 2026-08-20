<?php

namespace Tetranyble\Storage\Domain\CloudDrive\DTO;

final class TransferResult
{
    /** @param  array<array{path: string, error: string}>  $errors */
    public function __construct(
        public readonly CloudFile $root,
        public readonly int       $filesCopied,
        public readonly int       $foldersCreated,
        public readonly array     $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return ! empty($this->errors);
    }

    public function toArray(): array
    {
        return [
            'root'            => $this->root->toArray(),
            'files_copied'    => $this->filesCopied,
            'folders_created' => $this->foldersCreated,
            'errors'          => $this->errors,
            'has_errors'      => $this->hasErrors(),
        ];
    }
}
