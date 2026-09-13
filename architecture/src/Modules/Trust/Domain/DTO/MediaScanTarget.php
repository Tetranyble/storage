<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Trust\Domain\DTO;

use Tetranyble\Storage\Modules\Trust\Domain\ValueObject\MediaScanId;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\FileSize;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\MimeType;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\StoragePath;

final readonly class MediaScanTarget
{
    public function __construct(
        public MediaScanId $mediaId,
        public Disk $disk,
        public StoragePath $path,
        public ?FileSize $size = null,
        public ?MimeType $mimeType = null,
        public ?string $originalName = null,
    ) {}
}
