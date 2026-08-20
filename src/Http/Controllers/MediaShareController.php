<?php

namespace Tetranyble\Storage\Http\Controllers;

use Illuminate\Http\Request;
use Tetranyble\Storage\Contracts\Workspace;
use Tetranyble\Storage\Domain\Media\MediaLibraryService;
use Tetranyble\Storage\Domain\Media\MediaShareService;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\MediaShare;

class MediaShareController extends StorageController
{
    public function __construct(
        Workspace $workspace,
        protected readonly MediaShareService $shares,
        protected readonly MediaLibraryService $library,
    ) {
        parent::__construct($workspace);
    }

    public function download(Request $request, string $token)
    {
        $share = $this->shares->resolveByToken($token);
        abort_unless($share instanceof MediaShare, 404);

        $currentWorkspace = $this->workspace->currentWorkspace($request);
        if ($currentWorkspace && (string) $share->workspace_id !== (string) $currentWorkspace->getKey()) {
            abort(404);
        }

        $media = $share->shareable;
        abort_unless(
            $media instanceof Media
            && (string) $media->workspace_id === (string) $share->workspace_id,
            404,
        );

        $this->shares->validateAccess($share, $request->input('password'));
        $this->shares->incrementDownloads($share);

        return $this->library->streamDownload($media);
    }
}
