<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\DirectUpload\Domain\DTO;

use Tetranyble\Storage\Modules\DirectUpload\Domain\ValueObject\ETag;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\FileSize;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\MimeType;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\Sha256Checksum;

final readonly class DirectUploadObject
{
    public function __construct(
        public FileSize $size,
        public ?MimeType $mimeType = null,
        public ?Sha256Checksum $sha256 = null,
        public ?ETag $etag = null,
    ) {}
}
