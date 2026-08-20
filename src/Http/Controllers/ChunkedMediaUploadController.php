<?php

namespace Tetranyble\Storage\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Tetranyble\Storage\Contracts\ResumableUploadManager;
use Tetranyble\Storage\Contracts\Workspace;
use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Domain\FileSystem\DTO\UploadSessionOptions;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\Media\WorkspaceFileManagerService;
use Tetranyble\Storage\Enums\MediaPurpose;

class ChunkedMediaUploadController extends StorageController
{
    public function __construct(
        Workspace $workspace,
        protected readonly ResumableUploadManager $uploads,
        protected readonly WorkspaceFileManagerService $manager,
    ) {
        parent::__construct($workspace);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:191'],
            'original_name' => ['required', 'string', 'max:255'],
            'total_chunks' => ['required', 'integer', 'min:1'],
            'total_size' => ['nullable', 'integer', 'min:0'],
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
        $folder = $this->manager->resolveFolderById(
            $workspace,
            isset($validated['folder_id']) ? (int) $validated['folder_id'] : null,
        );

        $session = $this->uploads->startSession(new UploadSessionOptions(
            identifier: $validated['identifier'],
            upload: new MediaUploadOptions(
                workspaceId: (int) $workspace->getKey(),
                userId: $actor ? (int) $actor->getKey() : null,
                folderId: $folder?->getKey(),
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
        ));

        return $this->success('Upload session ready.', ['upload' => $this->uploads->progress($session)], 201);
    }

    public function update(Request $request, string $uploadSession, int $chunk): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file'],
            'checksum' => ['nullable', 'string', 'size:64'],
        ]);

        $session = $this->uploadSession($this->workspace($request), $uploadSession);
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
        $session = $this->uploadSession($this->workspace($request), $uploadSession);

        return $this->success('Upload progress loaded.', ['upload' => $this->uploads->progress($session)]);
    }

    public function finalize(Request $request, string $uploadSession): JsonResponse
    {
        $session = $this->uploadSession($this->workspace($request), $uploadSession);
        $media = $this->uploads->finalizeSession($session);

        return $this->success('Upload finalized.', ['media' => $this->mediaPayload($media)]);
    }

    public function destroy(Request $request, string $uploadSession): JsonResponse
    {
        $session = $this->uploadSession($this->workspace($request), $uploadSession);
        $this->uploads->cancelSession($session);

        return $this->success('Upload cancelled.');
    }
}
