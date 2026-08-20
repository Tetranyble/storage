<?php

namespace Tetranyble\Storage\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Tetranyble\Storage\Contracts\Workspace;
use Tetranyble\Storage\Contracts\RemoteMediaImporter;
use Tetranyble\Storage\Domain\FileSystem\Contracts\MediaUploader;
use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\Media\WorkspaceFileManagerService;
use Tetranyble\Storage\Enums\MediaPurpose;

class MediaController extends StorageController
{
    public function __construct(
        Workspace $workspace,
        protected readonly MediaUploader $uploads,
        protected readonly WorkspaceFileManagerService $manager,
        protected readonly RemoteMediaImporter $remoteImports,
    ) {
        parent::__construct($workspace);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file'],
            'description' => ['nullable', 'string', 'max:255'],
            'attribution' => ['nullable', 'string', 'max:255'],
            'directory' => ['nullable', 'string', 'max:191'],
            'purpose' => ['nullable', Rule::enum(MediaPurpose::class)],
            'disk' => ['nullable', Rule::enum(Disk::class)],
            'module' => ['nullable', 'string', 'max:64'],
            'folder_id' => ['nullable', 'integer'],
            'temporary' => ['nullable', 'boolean'],
            'replace_existing' => ['nullable', 'boolean'],
            'make_current' => ['nullable', 'boolean'],
            'custom_properties' => ['nullable', 'array'],
        ]);

        $workspace = $this->workspace($request);
        $actor = $this->actor($request);
        $folder = $this->manager->resolveFolderById(
            $workspace,
            isset($validated['folder_id']) ? (int) $validated['folder_id'] : null,
        );

        $media = $this->uploads->uploadUploadedFile(
            $validated['file'],
            MediaUploadOptions::forStandalone(
                workspaceId: (int) $workspace->getKey(),
                userId: $actor ? (int) $actor->getKey() : null,
                folderId: $folder?->getKey(),
                purpose: isset($validated['purpose'])
                    ? MediaPurpose::from($validated['purpose'])
                    : MediaPurpose::GENERAL,
                disk: isset($validated['disk']) ? Disk::from($validated['disk']) : null,
                directory: (string) ($validated['directory'] ?? 'media'),
                module: (string) ($validated['module'] ?? 'media'),
                temporary: (bool) ($validated['temporary'] ?? false),
                customProperties: (array) ($validated['custom_properties'] ?? []),
                label: $validated['description'] ?? null,
                attribution: $validated['attribution'] ?? null,
                makeCurrent: (bool) ($validated['make_current'] ?? true),
            ),
        );

        return $this->success('Media uploaded.', ['media' => $this->mediaPayload($media)], 201);
    }

    public function show(Request $request, string $media): JsonResponse
    {
        $resolved = $this->media($this->workspace($request), $media);

        return $this->success('Media loaded.', ['media' => $this->mediaPayload($resolved)]);
    }

    public function importUrl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'description' => ['nullable', 'string', 'max:255'],
            'attribution' => ['nullable', 'string', 'max:255'],
            'directory' => ['nullable', 'string', 'max:191'],
            'purpose' => ['nullable', Rule::enum(MediaPurpose::class)],
            'disk' => ['nullable', Rule::enum(Disk::class)],
            'driver' => ['nullable', Rule::enum(Disk::class)],
            'module' => ['nullable', 'string', 'max:64'],
            'folder_id' => ['nullable', 'integer'],
            'temporary' => ['nullable', 'boolean'],
            'make_current' => ['nullable', 'boolean'],
            'custom_properties' => ['nullable', 'array'],
        ]);
        if (isset($validated['disk'], $validated['driver'])) {
            throw ValidationException::withMessages([
                'driver' => 'Specify either driver or disk, not both.',
            ]);
        }

        $workspace = $this->workspace($request);
        $actor = $this->actor($request);
        $folder = $this->manager->resolveFolderById(
            $workspace,
            isset($validated['folder_id']) ? (int) $validated['folder_id'] : null,
        );
        if ($folder) {
            $this->manager->authorizeUploadToFolder($workspace, $folder, $actor);
        }

        $driver = $validated['driver'] ?? $validated['disk'] ?? null;
        $media = $this->remoteImports->uploadFromUrl(
            $validated['url'],
            MediaUploadOptions::forStandalone(
                workspaceId: (int) $workspace->getKey(),
                userId: $actor ? (int) $actor->getKey() : null,
                folderId: $folder?->getKey(),
                purpose: isset($validated['purpose'])
                    ? MediaPurpose::from($validated['purpose'])
                    : MediaPurpose::GENERAL,
                disk: is_string($driver) ? Disk::from($driver) : null,
                directory: (string) ($validated['directory'] ?? 'media'),
                module: (string) ($validated['module'] ?? 'media'),
                temporary: (bool) ($validated['temporary'] ?? false),
                customProperties: (array) ($validated['custom_properties'] ?? []),
                label: $validated['description'] ?? null,
                attribution: $validated['attribution'] ?? null,
                makeCurrent: (bool) ($validated['make_current'] ?? true),
            ),
        );

        return $this->success('Remote media imported.', ['media' => $this->mediaPayload($media)], 201);
    }

    public function update(Request $request, string $media): JsonResponse
    {
        $validated = $request->validate([
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'attribution' => ['sometimes', 'nullable', 'string', 'max:255'],
            'custom_properties' => ['sometimes', 'nullable', 'array'],
        ]);

        $resolved = $this->media($this->workspace($request), $media);
        $resolved->fill($validated)->save();

        return $this->success('Media updated.', ['media' => $this->mediaPayload($resolved->refresh())]);
    }

    public function destroy(Request $request, string $media): JsonResponse
    {
        $workspace = $this->workspace($request);
        $resolved = $this->media($workspace, $media);
        $this->manager->trashMedia($workspace, $resolved, $this->actor($request));

        return $this->success('Media moved to trash.');
    }

    public function setCurrent(Request $request, string $media): JsonResponse
    {
        $workspace = $this->workspace($request);
        $selected = $this->manager->setCurrentMedia(
            $workspace,
            $this->media($workspace, $media),
            $this->actor($request),
        );

        return $this->success('Media selected as current.', ['media' => $this->mediaPayload($selected)]);
    }
}
