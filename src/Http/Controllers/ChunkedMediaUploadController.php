<?php

namespace Tetranyble\Storage\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Tetranyble\Storage\Application\Uploads\ResumableUploadSessionGuard;
use Tetranyble\Storage\Application\Uploads\StartResumableUpload;
use Tetranyble\Storage\Contracts\ResumableUploadManager;
use Tetranyble\Storage\Contracts\Workspace;
use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Domain\FileSystem\DTO\UploadSessionOptions;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Enums\MediaPurpose;

class ChunkedMediaUploadController extends StorageController
{
    public function __construct(
        Workspace $workspace,
        protected readonly ResumableUploadManager $uploads,
        protected readonly StartResumableUpload $startUpload,
        protected readonly ResumableUploadSessionGuard $sessionGuard,
    ) {
        parent::__construct($workspace);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:191'],
            'original_name' => ['required', 'string', 'max:255'],
            'total_chunks' => ['required', 'integer', 'min:1'],
            'total_size' => ['nullable', 'integer', 'min:0', 'max:'.$this->uploadMaxBytes()],
            'chunk_size' => ['nullable', 'integer', 'min:1'],
            'mime_type' => ['nullable', 'string', 'max:191'],
            'expires_in_minutes' => ['nullable', 'integer', 'min:5', 'max:10080'],
            'purpose' => ['nullable', Rule::enum(MediaPurpose::class)],
            'disk' => ['nullable', Rule::enum(Disk::class)],
            'directory' => ['nullable', 'string', 'max:191'],
            'module' => ['nullable', 'string', 'max:64'],
            'folder_id' => ['nullable', 'integer'],
            'temporary' => ['nullable', 'boolean'],
            'make_current' => ['nullable', 'boolean'],
            'custom_properties' => ['nullable', 'array'],
        ]);

        $workspace = $this->workspace($request);
        $actor = $this->actor($request);
        $session = $this->startUpload->handle(
            $workspace,
            new UploadSessionOptions(
                identifier: $validated['identifier'],
                upload: new MediaUploadOptions(
                    workspaceId: (int) $workspace->getKey(),
                    userId: $actor ? (int) $actor->getKey() : null,
                    folderId: isset($validated['folder_id']) ? (int) $validated['folder_id'] : null,
                    disk: isset($validated['disk']) ? Disk::from($validated['disk']) : null,
                    directory: (string) ($validated['directory'] ?? 'media'),
                    purpose: isset($validated['purpose'])
                        ? MediaPurpose::from($validated['purpose'])
                        : MediaPurpose::GENERAL,
                    module: (string) ($validated['module'] ?? 'media'),
                    customProperties: (array) ($validated['custom_properties'] ?? []),
                    temporary: (bool) ($validated['temporary'] ?? false),
                    makeCurrent: (bool) ($validated['make_current'] ?? true),
                    originalName: $validated['original_name'],
                ),
                totalChunks: (int) $validated['total_chunks'],
                totalSize: isset($validated['total_size']) ? (int) $validated['total_size'] : null,
                chunkSize: isset($validated['chunk_size']) ? (int) $validated['chunk_size'] : null,
                mimeType: $validated['mime_type'] ?? null,
                expiresAt: isset($validated['expires_in_minutes'])
                    ? now()->addMinutes((int) $validated['expires_in_minutes'])
                    : null,
            ),
            $actor,
        );

        return $this->success('Upload session ready.', ['upload' => $this->uploads->progress($session)], 201);
    }

    public function update(Request $request, string $uploadSession, int $chunk): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.$this->chunkMaxKilobytes()],
            'checksum' => ['nullable', 'string', 'size:64'],
        ]);

        $workspace = $this->workspace($request);
        $session = $this->uploadSession($workspace, $uploadSession);
        $this->sessionGuard->authorize($workspace, $session, $this->actor($request));
        $session = $this->uploads->appendChunk(
            $session,
            $validated['file'],
            $chunk,
            $validated['checksum'] ?? null,
        );

        return $this->success('Chunk accepted.', ['upload' => $this->uploads->progress($session)]);
    }

    public function show(Request $request, string $uploadSession): JsonResponse
    {
        $workspace = $this->workspace($request);
        $session = $this->uploadSession($workspace, $uploadSession);
        $this->sessionGuard->authorize($workspace, $session, $this->actor($request));

        return $this->success('Upload progress loaded.', ['upload' => $this->uploads->progress($session)]);
    }

    public function finalize(Request $request, string $uploadSession): JsonResponse
    {
        $workspace = $this->workspace($request);
        $session = $this->uploadSession($workspace, $uploadSession);
        $this->sessionGuard->authorize($workspace, $session, $this->actor($request));
        $media = $this->uploads->finalizeSession($session);

        return $this->success('Upload finalized.', ['media' => $this->mediaPayload($media)]);
    }

    public function destroy(Request $request, string $uploadSession): JsonResponse
    {
        $workspace = $this->workspace($request);
        $session = $this->uploadSession($workspace, $uploadSession);
        $this->sessionGuard->authorize($workspace, $session, $this->actor($request));
        $this->uploads->cancelSession($session);

        return $this->success('Upload cancelled.');
    }

    private function uploadMaxBytes(): int
    {
        return max(1, (int) config('tetranyble-storage.uploads.max_size', 50 * 1024 * 1024));
    }

    private function chunkMaxKilobytes(): int
    {
        $maxBytes = max(1, (int) config(
            'tetranyble-storage.uploads.max_chunk_size',
            min($this->uploadMaxBytes(), 10 * 1024 * 1024),
        ));

        return max(1, (int) ceil($maxBytes / 1024));
    }
}
