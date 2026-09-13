<?php

namespace Tetranyble\Storage\Http\Controllers;

use Illuminate\Http\Request;
use Tetranyble\Storage\Http\Contracts\WorkspaceContext;
use Tetranyble\Storage\Http\Responses\MediaStreamResponder;
use Tetranyble\Storage\Http\Routing\WorkspaceRouteResolver;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Processing\Application\MediaDeliveryGuard;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Application\MediaShareService;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Persistence\Eloquent\Models\MediaShare;

class MediaShareController extends StorageController
{
    public function __construct(
        WorkspaceContext $workspace,
        WorkspaceRouteResolver $routes,
        protected readonly MediaShareService $shares,
        protected readonly MediaStreamResponder $responder,
        protected readonly MediaDeliveryGuard $delivery,
    ) {
        parent::__construct($workspace, $routes);
    }

    public function download(Request $request, string $token)
    {
        $share = $this->shares->resolveByToken($token);
        if (! $share instanceof MediaShare) {
            throw new ResourceNotFoundException;
        }

        $currentWorkspace = $this->workspace->currentWorkspace($request);
        if ($currentWorkspace && (string) $share->workspace_id !== (string) $currentWorkspace->getKey()) {
            throw new ResourceNotFoundException;
        }

        $media = $share->shareable;
        if (! $media instanceof Media
            || (string) $media->workspace_id !== (string) $share->workspace_id) {
            throw new ResourceNotFoundException;
        }

        // Do not consume a limited download slot for media that is quarantined.
        $this->delivery->assertDeliverable($media);
        // Never consume share passwords from a GET query string; URLs leak through browser/history/proxy logs.
        $password = $request->isMethod('post') ? $request->input('password') : null;
        $this->shares->consumeDownloadAccess($share, is_string($password) ? $password : null);

        return $this->responder->stream($media);
    }
}
