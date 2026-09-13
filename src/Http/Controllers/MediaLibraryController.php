<?php

namespace Tetranyble\Storage\Http\Controllers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tetranyble\Storage\Modules\Folder\Application\{CreateFolder, EmptyTrash};
use Tetranyble\Storage\Modules\Media\Application\DeleteMedia;
use Tetranyble\Storage\Modules\Media\Application\MoveMedia;
use Tetranyble\Storage\Modules\Media\Application\RenameMedia;
use Tetranyble\Storage\Modules\Media\Application\RestoreMedia;
use Tetranyble\Storage\Modules\Media\Application\TrashMedia;
use Tetranyble\Storage\Modules\Media\Application\UploadMedia;
use Tetranyble\Storage\Http\{Contracts\WorkspaceContext, Routing\WorkspaceRouteResolver};
use Tetranyble\Storage\Http\Adapters\LaravelIncomingFile;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageService;
use Tetranyble\Storage\Modules\Media\Infrastructure\Application\MediaLibraryService;
use Tetranyble\Storage\Modules\Sharing\Application\CreateMediaShare;
use Tetranyble\Storage\Modules\Sharing\Application\RevokeMediaShare;
use Tetranyble\Storage\Modules\Workspace\Application\Contracts\WorkspaceReadModel;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\{ActivityWorkspace, BrowseWorkspace, RecentWorkspace, SearchWorkspace, TrashWorkspace};
class MediaLibraryController extends StorageController
{
    public function __construct(
        WorkspaceContext $workspace,
        WorkspaceRouteResolver $routes,
        protected readonly MediaLibraryService $library,
        protected readonly StorageService $storage,
        protected readonly WorkspaceReadModel $queries,
        protected readonly UploadMedia $uploadMedia,
        protected readonly TrashMedia $trashMedia,
        protected readonly RestoreMedia $restoreMedia,
        protected readonly DeleteMedia $deleteMedia,
        protected readonly MoveMedia $moveMedia,
        protected readonly RenameMedia $renameMedia,
        protected readonly CreateFolder $createFolder,
        protected readonly EmptyTrash $emptyTrash,
        protected readonly CreateMediaShare $createMediaShare,
        protected readonly RevokeMediaShare $revokeMediaShare,
    ) {
        parent::__construct($workspace, $routes);
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

        $payload = $this->queries->browse(new BrowseWorkspace(
            workspace: $this->workspace($request),
            relativePath: (string) ($validated['path'] ?? ''),
            search: (string) ($validated['search'] ?? ''),
            actor: $this->actor($request),
            sortBy: (string) ($validated['sort_by'] ?? 'name'),
            sortDir: (string) ($validated['sort_dir'] ?? 'asc'),
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? 50),
        ));

        return $this->success('Media library loaded.', $payload);
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:191'],
            'folder_cursor' => ['nullable', 'string', 'max:4096'],
            'file_cursor' => ['nullable', 'string', 'max:4096'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'sort_by' => ['nullable', 'string', 'in:created_at,updated_at'],
            'sort_dir' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        return $this->success('Search results loaded.', $this->queries->search(new SearchWorkspace(
            workspace: $this->workspace($request),
            query: (string) $validated['query'],
            actor: $this->actor($request),
            folderCursor: $validated['folder_cursor'] ?? null,
            fileCursor: $validated['file_cursor'] ?? null,
            perPage: (int) ($validated['per_page'] ?? 50),
            sortBy: (string) ($validated['sort_by'] ?? 'updated_at'),
            sortDir: (string) ($validated['sort_dir'] ?? 'desc'),
        )));
    }

    public function recent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'folder_cursor' => ['nullable', 'string', 'max:4096'],
            'file_cursor' => ['nullable', 'string', 'max:4096'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return $this->success('Recent resources loaded.', $this->queries->recent(new RecentWorkspace(
            workspace: $this->workspace($request),
            actor: $this->actor($request),
            folderCursor: $validated['folder_cursor'] ?? null,
            fileCursor: $validated['file_cursor'] ?? null,
            perPage: (int) ($validated['per_page'] ?? 25),
        )));
    }

    public function activity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cursor' => ['nullable', 'string', 'max:4096'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return $this->success('Activity loaded.', $this->queries->activity(new ActivityWorkspace(
            workspace: $this->workspace($request),
            actor: $this->actor($request),
            cursor: $validated['cursor'] ?? null,
            perPage: (int) ($validated['per_page'] ?? 50),
        )));
    }

    public function usage(Request $request): JsonResponse
    {
        $usage = $this->storage->usage($this->workspace($request));

        return $this->success('Storage usage loaded.', [
            'usage' => [
                'used_bytes' => $usage->used->bytes,
                'quota_bytes' => $usage->quota->bytes,
                'remaining_bytes' => $usage->remaining()->bytes,
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

        return $this->success('Trash loaded.', $this->queries->trash(new TrashWorkspace(
            workspace: $this->workspace($request),
            sortBy: (string) ($validated['sort_by'] ?? 'deleted_at'),
            sortDir: (string) ($validated['sort_dir'] ?? 'desc'),
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? 50),
        )));
    }

    public function emptyTrash(Request $request): JsonResponse
    {
        $this->emptyTrash->handle($this->workspace($request));

        return $this->success('Trash emptied.');
    }

    public function createFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $workspace = $this->workspace($request);
        $folder = $this->createFolder->handle(
            $workspace,
            $validated['name'],
            isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
            $this->actor($request),
        );

        return $this->success('Folder created.', ['folder' => $folder->toArray()], 201);
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
            array_map(static fn ($file) => LaravelIncomingFile::fromUploadedFile($file), $validated['files']),
            isset($validated['folder_id']) ? (int) $validated['folder_id'] : null,
            $this->actor($request),
        );

        return $this->success('Files uploaded.', [
            'uploaded_count' => count($uploaded),
            'media' => array_map(fn ($media) => $this->mediaPayload($media), $uploaded),
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

        $resolved = $this->renameMedia->handle(
            $workspace,
            $this->media($workspace, $media),
            $validated['name'],
            $this->actor($request),
        );

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
        $share = $this->createMediaShare->handle(
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
        $this->revokeMediaShare->handle(
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
