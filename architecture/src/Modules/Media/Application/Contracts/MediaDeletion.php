<?php

namespace Tetranyble\Storage\Modules\Media\Application\Contracts;

interface MediaDeletion
{
    public function delete(object $media): void;
}
