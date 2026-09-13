<?php

namespace Tetranyble\Storage\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Tetranyble\Storage\Http\Contracts\WorkspaceContext;
use Tetranyble\Storage\Http\Routing\WorkspaceRouteResolver;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;
use Tetranyble\Storage\Modules\DirectUpload\Infrastructure\Persistence\Eloquent\Models\DirectUploadSession;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Persistence\Eloquent\Models\MediaShare;
use Tetranyble\Storage\Modules\Upload\Infrastructure\Persistence\Eloquent\Models\UploadSession;

abstract class StorageController extends Controller
{
    public function __construct(
        protected readonly WorkspaceContext $workspace,
        private readonly WorkspaceRouteResolver $routes,
    ) {}

    protected function workspace(Request $request): Model
    {
        /** @var Model $workspace */
        $workspace = $this->workspace->requireWorkspace($request);
        return $workspace;
    }

    protected function actor(Request $request): ?Model
    {
        $actor = $this->workspace->currentActor($request);
        return $actor instanceof Model ? $actor : null;
    }

    protected function media(Model $workspace, string|int $key, bool $withTrashed = false): Media
    {
        /** @var Media $media */
        $media = $this->routes->resolve(Media::class, $workspace, $key, $withTrashed);
        return $media;
    }

    protected function folder(Model $workspace, string|int $key, bool $withTrashed = false): Folder
    {
        /** @var Folder $folder */
        $folder = $this->routes->resolve(Folder::class, $workspace, $key, $withTrashed);
        return $folder;
    }

    protected function share(Model $workspace, string|int $key): MediaShare
    {
        /** @var MediaShare $share */
        $share = $this->routes->resolve(MediaShare::class, $workspace, $key);
        return $share;
    }

    protected function uploadSession(Model $workspace, string|int $key): UploadSession
    {
        /** @var UploadSession $session */
        $session = $this->routes->resolve(UploadSession::class, $workspace, $key);
        return $session;
    }

    protected function directUploadSession(Model $workspace, string|int $key): DirectUploadSession
    {
        /** @var DirectUploadSession $session */
        $session = $this->routes->resolve(DirectUploadSession::class, $workspace, $key);
        return $session;
    }

    protected function drive(Model $workspace, string|int $key): ConnectedDrive
    {
        /** @var ConnectedDrive $drive */
        $drive = $this->routes->resolve(ConnectedDrive::class, $workspace, $key);
        return $drive;
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
}
