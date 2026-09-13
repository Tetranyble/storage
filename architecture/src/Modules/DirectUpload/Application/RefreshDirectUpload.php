<?php

namespace Tetranyble\Storage\Modules\DirectUpload\Application;

use Tetranyble\Storage\Modules\DirectUpload\Application\Contracts\DirectUploadManager;
use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadProviderPlan;

final class RefreshDirectUpload
{
    public function __construct(
        private readonly DirectUploadManager $uploads,
        private readonly DirectUploadSessionGuard $guard,
    ) {}

    public function handle(object $workspace, object $session, array $partNumbers = [], ?object $actor = null): DirectUploadProviderPlan
    {
        $this->guard->authorize($workspace, $session, $actor);

        return $this->uploads->refresh($session, $partNumbers);
    }
}
