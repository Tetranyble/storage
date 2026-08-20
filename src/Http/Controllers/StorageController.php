<?php

namespace Tetranyble\Storage\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;
use Tetranyble\Storage\Contracts\Workspace;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\MediaShare;
use Tetranyble\Storage\Models\UploadSession;

abstract class StorageController extends Controller
{
    public function __construct(protected readonly Workspace $workspace) {}

    protected function workspace(Request $request): Model
    {
        return $this->workspace->requireWorkspace($request);
    }

    protected function actor(Request $request): ?Model
    {
        return $this->workspace->currentActor($request);
    }

    protected function media(Model $workspace, string|int $key, bool $withTrashed = false): Media
    {
        /** @var Media $media */
        $media = $this->workspaceResource('media', Media::class, $workspace, $key, $withTrashed);

        return $media;
    }

    protected function folder(Model $workspace, string|int $key, bool $withTrashed = false): Folder
    {
        /** @var Folder $folder */
        $folder = $this->workspaceResource('folder', Folder::class, $workspace, $key, $withTrashed);

        return $folder;
    }

    protected function share(Model $workspace, string|int $key): MediaShare
    {
        /** @var MediaShare $share */
        $share = $this->workspaceResource('media_share', MediaShare::class, $workspace, $key);

        return $share;
    }

    protected function uploadSession(Model $workspace, string|int $key): UploadSession
    {
        /** @var UploadSession $session */
        $session = $this->workspaceResource('upload_session', UploadSession::class, $workspace, $key);

        return $session;
    }

    protected function success(string $message, array $data = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function mediaPayload(Media $media): array
    {
        return [
            'id' => $media->getKey(),
            'uuid' => $media->uuid,
            'name' => $media->original_name ?: basename((string) $media->path),
            'description' => $media->description,
            'attribution' => $media->attribution,
            'mime_type' => $media->mime_type,
            'size' => (int) ($media->size ?? 0),
            'disk' => $media->disk instanceof \BackedEnum ? $media->disk->value : $media->disk,
            'path' => $media->path,
            'url' => $media->url,
            'folder_id' => $media->folder_id,
            'workspace_id' => $media->workspace_id,
            'is_temporary' => (bool) $media->is_temporary,
            'current' => (bool) $media->current,
            'created_at' => optional($media->created_at)?->toIso8601String(),
            'updated_at' => optional($media->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * Resolve the route value only after applying the workspace constraint.
     */
    private function workspaceResource(
        string $configKey,
        string $expectedClass,
        Model $workspace,
        string|int $key,
        bool $withTrashed = false,
    ): Model {
        $modelClass = config("tetranyble-storage.models.{$configKey}", $expectedClass);
        if (! is_string($modelClass) || ! is_a($modelClass, $expectedClass, true)) {
            throw new RuntimeException("The configured storage {$configKey} model must extend {$expectedClass}.");
        }

        /** @var Model $prototype */
        $prototype = new $modelClass();
        $query = $modelClass::query()->where(
            (string) config('tetranyble-storage.workspace.resource_foreign_key', 'workspace_id'),
            $workspace->getKey(),
        );

        if ($withTrashed && in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }

        return $query->where(function ($builder) use ($prototype, $key): void {
            $builder->where($prototype->getRouteKeyName(), $key);

            if ($prototype->getRouteKeyName() !== $prototype->getKeyName()) {
                $builder->orWhere($prototype->getKeyName(), $key);
            }

            if ($prototype->getRouteKeyName() !== 'uuid') {
                $builder->orWhere('uuid', $key);
            }
        })->firstOrFail();
    }
}
