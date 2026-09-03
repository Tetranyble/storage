<?php

namespace Tetranyble\Storage\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Tetranyble\Storage\Application\Media\DeleteMedia;
use Tetranyble\Storage\Application\Media\MoveMedia;
use Tetranyble\Storage\Application\Media\RenameMedia;
use Tetranyble\Storage\Application\Media\RestoreMedia;
use Tetranyble\Storage\Application\Media\TrashMedia;
use Tetranyble\Storage\Application\Media\UploadMedia;
use Tetranyble\Storage\Contracts\Workspace;
use Tetranyble\Storage\Domain\FileSystem\StorageService;
use Tetranyble\Storage\Domain\Media\MediaLibraryService;
use Tetranyble\Storage\Domain\Media\WorkspaceFileManagerService;
use Tetranyble\Storage\Domain\Media\WorkspaceFileQueryService;

class MediaLibraryController extends StorageController
{
    public function __construct(
        Workspace $workspace,
        protected readonly MediaLibraryService $library,
        protected readonly StorageService $storage,
        protected readonly WorkspaceFileManagerService $manager,
        protected readonly WorkspaceFileQueryService $queries,
        protected readonly UploadMedia $uploadMedia,
        protected readonly TrashMedia $trashMedia,
        protected readonly RestoreMedia $restoreMedia,
        protected readonly DeleteMedia $deleteMedia,
        protected readonly MoveMedia $moveMedia,
        protected readonly RenameMedia $renameMedia,
    ) {
        parent::__construct($workspace);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => ['nullable', 'string', 'max:1000'],
            'search' => ['nullable', 'string', 'max:191'],
            'sort_by' => ['nullable', 'string', 'in:name,created_at,updated_at'],
            'sort_dir' => ['nullable', 'string', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $payload = $this->queries->indexPayload(
            workspace: $this->workspace($request),
            relativePath: (string) ($validated['path'] ?? ''),
            search: (string) ($validated['search'] ?? ''),
            actor: $this->actor($request),
            sortBy: (string) ($validated['sort_by'] ?? 'name'),
            sortDir: (string) ($validated['sort_dir'] ?? 'asc'),
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? 50),
        );

        return $this->success('Media library loaded.', $payload);
    }

    public function usage(Request $request): JsonResponse
    {
        $usage = $this->storage->usage($this->workspace($request));

        return $this->success('Storage usage loaded.', [
            'usage' => [
                'used_bytes' => $usage->usedBytes,
                'quota_bytes' => $usage->quotaBytes,
                'remaining_bytes' => $usage->remainingBytes(),
                'percent' => $usage->percentage(),
                'near_limit' => $usage->isNearLimit(),
            ],
        ]);
    }

    public function trash(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sort_by' => ['nullable', 'string', 'in:name,deleted_at'],
            'sort_dir' => ['nullable', 'string', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return $this->success('Trash loaded.', $this->queries->trashPayload(
            workspace: $this->workspace($request),
            sortBy: (string) ($validated['sort_by'] ?? 'deleted_at'),
            sortDir: (string) ($validated['sort_dir'] ?? 'desc'),
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? 50),
        ));
    }

    public function emptyTrash(Request $request): JsonResponse
    {
        $this->manager->emptyTrash($this->workspace($request));

        return $this->success('Trash emptied.');
    }

    public function createFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $workspace = $this->workspace($request);
        $parent = $this->manager->resolveFolderById(
            $workspace,
            isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
        );
        $folder = $this->manager->createFolder(
            $workspace,
            $validated['name'],
            $parent,
            $this->actor($request),
        );

        return $this->success('Folder created.', ['folder' => $folder->toArray()], 201);
    }

    public function moveToFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'media_ids' => ['required', 'array', 'min:1'],
            'media_ids.*' => ['integer'],
            'folder_id' => ['nullable', 'integer'],
        ]);

        $workspace = $this->workspace($request);
        $folderId = isset($validated['folder_id']) ? (int) $validated['folder_id'] : null;
        $moved = 0;

        foreach ($validated['media_ids'] as $mediaId) {
            try {
                $media = $this->media($workspace, (int) $mediaId, true);
            } catch (ModelNotFoundException) {
                continue;
            }

            $this->moveMedia->handle($workspace, $media, $folderId, $this->actor($request));
            $moved++;
        }

        return $this->success("Moved {$moved} file(s).", ['moved' => $moved]);
    }

    public function archiveFolder(Request $request, string $folder): JsonResponse
    {
        $resolved = $this->folder($this->workspace($request), $folder);
        $this->library->archiveFolder($resolved, true);

        return $this->success('Folder archived.');
    }

    public function unarchiveFolder(Request $request, string $folder): JsonResponse
    {
        $resolved = $this->folder($this->workspace($request), $folder);
        $this->library->unarchiveFolder($resolved, true);

        return $this->success('Folder unarchived.');
    }

    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file', 'max:'.$this->uploadMaxKilobytes()],
            'folder_id' => ['nullable', 'integer'],
        ]);

        $workspace = $this->workspace($request);
        $uploaded = $this->uploadMedia->uploadLibraryFiles(
            $workspace,
            $validated['files'],
            isset($validated['folder_id']) ? (int) $validated['folder_id'] : null,
            $this->actor($request),
        );

        return $this->success('Files uploaded.', [
            'uploaded_count' => $uploaded->count(),
            'media' => $uploaded->map(fn ($media) => $this->mediaPayload($media))->values()->all(),
        ], 201);
    }

    public function destroy(Request $request, string $media): JsonResponse
    {
        $workspace = $this->workspace($request);
        $this->trashMedia->handle(
            $workspace,
            $this->media($workspace, $media),
            $this->actor($request),
        );

        return $this->success('File moved to trash.');
    }

    public function restore(Request $request, string $media): JsonResponse
    {
        $workspace = $this->workspace($request);
        $this->restoreMedia->handle(
            $workspace,
            $this->media($workspace, $media, true),
            $this->actor($request),
        );

        return $this->success('File restored.');
    }

    public function forceDelete(Request $request, string $media): JsonResponse
    {
        $workspace = $this->workspace($request);
        $this->deleteMedia->handle(
            $workspace,
            $this->media($workspace, $media, true),
            $this->actor($request),
        );

        return $this->success('File deleted permanently.');
    }

    public function move(Request $request, string $media): JsonResponse
    {
        $validated = $request->validate(['folder_id' => ['nullable', 'integer']]);
        $workspace = $this->workspace($request);
        $resolved = $this->moveMedia->handle(
            $workspace,
            $this->media($workspace, $media),
            isset($validated['folder_id']) ? (int) $validated['folder_id'] : null,
            $this->actor($request),
        );

        return $this->success('File moved.', ['media' => $this->mediaPayload($resolved)]);
    }

    public function rename(Request $request, string $media): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:191']]);
        $workspace = $this->workspace($request);

        try {
            $resolved = $this->renameMedia->handle(
                $workspace,
                $this->media($workspace, $media),
                $validated['name'],
                $this->actor($request),
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'data' => [],
            ], 422);
        }

        return $this->success('File renamed.', ['media' => $this->mediaPayload($resolved)]);
    }

    public function createShare(Request $request, string $media): JsonResponse
    {
        $validated = $request->validate([
            'access_level' => ['nullable', 'string', 'in:view,download'],
            'ttl_minutes' => ['nullable', 'integer', 'min:5', 'max:10080'],
            'max_downloads' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'password' => ['nullable', 'string', 'min:4', 'max:100'],
        ]);

        $workspace = $this->workspace($request);
        $resolved = $this->media($workspace, $media);
        $actor = $this->actor($request);
        $share = $this->manager->createShare(
            workspace: $workspace,
            media: $resolved,
            user: $actor,
            accessLevel: (string) ($validated['access_level'] ?? 'download'),
            ttlMinutes: isset($validated['ttl_minutes']) ? (int) $validated['ttl_minutes'] : null,
            maxDownloads: isset($validated['max_downloads']) ? (int) $validated['max_downloads'] : null,
            password: $validated['password'] ?? null,
            actor: $actor,
        );

        $routeName = (string) config('tetranyble-storage.routes.name', 'tetranyble-storage.').'shares.download';

        return $this->success('Share link created.', [
            'share' => [
                'id' => $share->getKey(),
                'token' => $share->token,
                'url' => route($routeName, ['token' => $share->token]),
                'expires_at' => optional($share->expires_at)?->toIso8601String(),
                'max_downloads' => $share->max_downloads,
                'downloads_count' => $share->downloads_count,
            ],
        ], 201);
    }

    public function revokeShare(Request $request, string $media, string $share): JsonResponse
    {
        $workspace = $this->workspace($request);
        $resolvedMedia = $this->media($workspace, $media);
        $resolvedShare = $this->share($workspace, $share);
        abort_unless(
            $resolvedShare->shareable_type === $resolvedMedia->getMorphClass()
            && (string) $resolvedShare->shareable_id === (string) $resolvedMedia->getKey(),
            404,
        );

        $this->manager->revokeShare(
            $workspace,
            $resolvedMedia,
            $resolvedShare,
            $this->actor($request),
        );

        return $this->success('Share link revoked.');
    }

    private function uploadMaxKilobytes(): int
    {
        $maxBytes = max(1, (int) config('tetranyble-storage.uploads.max_size', 50 * 1024 * 1024));

        return max(1, (int) ceil($maxBytes / 1024));
    }
}
