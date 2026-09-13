<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Media\Application\Contracts;

use Tetranyble\Storage\Modules\Storage\Application\DTO\IncomingFile;

interface MediaRevisionWriter
{
    public function createRevisionFromUpload(object $media, IncomingFile $file, ?int $userId = null): object;
    public function restoreRevision(object $revision, ?int $userId = null): object;
}
