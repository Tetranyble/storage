<?php

namespace Tetranyble\Storage\Domain\Media\DTO;

use Tetranyble\Storage\Models\MediaShare;

class MediaMailPayload
{
    public function __construct(
        public readonly string $type,
        public readonly string $filename,
        public readonly string $mime,
        public readonly string $disposition = 'attachment',
        public readonly ?string $content = null,
        public readonly ?string $url = null,
        public readonly ?MediaShare $share = null,
    ) {}

    public static function base64(string $filename, string $mime, string $content): self
    {
        return new self(
            type: 'base64',
            filename: $filename,
            mime: $mime,
            content: $content,
        );
    }

    public static function url(string $filename, string $mime, string $url, ?MediaShare $share = null): self
    {
        return new self(
            type: 'url',
            filename: $filename,
            mime: $mime,
            url: $url,
            share: $share,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'filename' => $this->filename,
            'mime' => $this->mime,
            'disposition' => $this->disposition,
            'content' => $this->content,
            'url' => $this->url,
            'share' => $this->share,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
