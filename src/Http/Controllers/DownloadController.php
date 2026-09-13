<?php

namespace Tetranyble\Storage\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tetranyble\Storage\Http\Contracts\WorkspaceContext;
use Tetranyble\Storage\Http\Responses\DownloadResponder;
use Tetranyble\Storage\Http\Routing\WorkspaceRouteResolver;
use Tetranyble\Storage\Modules\Download\Infrastructure\Application\DownloadService;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;

class DownloadController extends StorageController
{
    public function __construct(
        WorkspaceContext $workspace,
        WorkspaceRouteResolver $routes,
        protected readonly DownloadService $downloads,
        protected readonly DownloadResponder $responder,
    ) {
        parent::__construct($workspace, $routes);
    }

    /**
     * Download a single local Media file.
     *
     * GET /storage/media/{media}/download
     */
    public function show(Request $request, string $media): Response
    {
        $workspace = $this->workspace($request);
        $resolved = $this->media($workspace, $media);

        return $this->responder->response(
            $this->downloads->downloadMedia($workspace, $resolved, $this->actor($request))
        );
    }

    /**
     * Build and stream a ZIP archive of multiple local Media files.
     * Items the actor cannot view are silently skipped.
     *
     * POST /storage/media/zip
     * Body: { "items": ["uuid-1", "uuid-2", ...], "name": "my-archive" }
     */
    public function zip(Request $request): Response
    {
        $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*' => ['required', 'string'],
            'name' => ['sometimes', 'string', 'max:200'],
        ]);

        $workspace = $this->workspace($request);
        $mediaItems = collect($request->input('items'))
            ->map(function (string $key) use ($workspace) {
                try {
                    return $this->media($workspace, $key);
                } catch (ResourceNotFoundException) {
                    return null;
                }
            })
            ->filter()
            ->values()
            ->all();

        $result = $this->downloads->zipMedia(
            $workspace,
            $mediaItems,
            $this->actor($request),
            $request->input('name', 'download'),
        );

        return $this->responder->response($result['download']);
    }
}
