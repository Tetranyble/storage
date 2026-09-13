<?php

namespace Tetranyble\Storage\Modules\DirectUpload\Application;

use Tetranyble\Storage\Modules\DirectUpload\Application\Contracts\DirectUploadManager;

final class CancelDirectUpload
{
    public function __construct(
        private readonly DirectUploadManager $uploads,
        private readonly DirectUploadSessionGuard $guard,
    ) {}

    public function handle(object $workspace, object $session, ?object $actor = null): void
    {
        $this->guard->authorize($workspace, $session, $actor);
        $this->uploads->cancel($session);
    }
}
