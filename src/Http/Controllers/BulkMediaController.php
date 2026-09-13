<?php

namespace Tetranyble\Storage\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tetranyble\Storage\Modules\Media\Infrastructure\Application\Bulk\BulkMediaService;
use Tetranyble\Storage\Http\Contracts\WorkspaceContext;
use Tetranyble\Storage\Http\Routing\WorkspaceRouteResolver;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;

final class BulkMediaController extends StorageController
{
    public function __construct(
        WorkspaceContext $workspace,
        WorkspaceRouteResolver $routes,
        private readonly BulkMediaService $bulk,
    ) {
        parent::__construct($workspace, $routes);
    }

    public function trash(Request $request): JsonResponse
    {
        return $this->respond($request, fn ($workspace, $ids, $actor) => $this->bulk->trash($workspace, $ids, $actor));
    }

    public function restore(Request $request): JsonResponse
    {
        return $this->respond($request, fn ($workspace, $ids, $actor) => $this->bulk->restore($workspace, $ids, $actor));
    }

    public function delete(Request $request): JsonResponse
    {
        if (! (bool) config('tetranyble-storage.bulk.allow_permanent_delete', false)) {
            throw new ResourceNotFoundException();
        }

        return $this->respond($request, fn ($workspace, $ids, $actor) => $this->bulk->delete($workspace, $ids, $actor));
    }

    public function move(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'media_ids' => ['required', 'array', 'min:1'],
            'media_ids.*' => ['required', 'integer'],
            'folder_id' => ['nullable', 'integer'],
        ]);

        $payload = $this->bulk->move(
            $this->workspace($request),
            $validated['media_ids'],
            isset($validated['folder_id']) ? (int) $validated['folder_id'] : null,
            $this->actor($request),
        );

        return $this->success('Bulk move completed.', ['bulk' => $payload]);
    }

    private function respond(Request $request, callable $operation): JsonResponse
    {
        $validated = $request->validate([
            'media_ids' => ['required', 'array', 'min:1'],
            'media_ids.*' => ['required', 'integer'],
        ]);

        $payload = $operation(
            $this->workspace($request),
            $validated['media_ids'],
            $this->actor($request),
        );

        return $this->success('Bulk operation completed.', ['bulk' => $payload]);
    }
}
