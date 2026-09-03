<?php

namespace Tetranyble\Storage\Application\Uploads;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tetranyble\Storage\Application\Support\WorkspaceResourceGuard;
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Models\UploadSession;

class ResumableUploadSessionGuard
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly WorkspaceResourceGuard $resources,
    ) {}

    public function authorize(Model $workspace, UploadSession $session, ?Model $actor = null): void
    {
        if ((string) $session->workspace_id !== (string) $workspace->getKey()
            || ($session->user_id !== null
                && (! $actor || (string) $session->user_id !== (string) $actor->getKey()))) {
            throw (new ModelNotFoundException())->setModel(UploadSession::class, [$session->getKey()]);
        }

        if ($session->folder_id !== null) {
            $folder = $this->resources->folderById($workspace, (int) $session->folder_id);
            $this->access->authorizeEdit($workspace, $folder, $actor);
        }
    }
}
