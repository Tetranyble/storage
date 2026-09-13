<?php

namespace Tetranyble\Storage\Modules\DirectUpload\Application;

use Tetranyble\Storage\Modules\DirectUpload\Application\Contracts\DirectUploadManager;

final class GetDirectUpload
{
    public function __construct(
        private readonly DirectUploadManager $uploads,
        private readonly DirectUploadSessionGuard $guard,
    ) {}

    public function handle(object $workspace, object $session, ?object $actor = null): array
    {
        $this->guard->authorize($workspace, $session, $actor);

        return $this->uploads->progress($session);
    }
}
