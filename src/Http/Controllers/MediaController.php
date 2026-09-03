<?php

namespace Tetranyble\Storage\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Tetranyble\Storage\Application\Media\SetCurrentMedia;
use Tetranyble\Storage\Application\Media\TrashMedia;
use Tetranyble\Storage\Application\Media\UpdateMedia;
use Tetranyble\Storage\Application\Media\UploadMedia;
use Tetranyble\Storage\Application\Queries\GetMedia;
use Tetranyble\Storage\Application\Uploads\ImportRemoteMedia;
use Tetranyble\Storage\Contracts\Workspace;
use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Enums\MediaPurpose;

class MediaController extends StorageController
{
    public function __construct(
        Workspace $workspace,
        protected readonly UploadMedia $uploadMedia,
        protected readonly ImportRemoteMedia $remoteImports,
        protected readonly GetMedia $getMedia,
        protected readonly UpdateMedia $updateMedia,
        protected readonly TrashMedia $trashMedia,
        protected readonly SetCurrentMedia $setCurrentMedia,
    ) {
        parent::__construct($workspace);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.$this->uploadMaxKilobytes()],
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
        $media = $this->uploadMedia->handle(
            $workspace,
            $validated['file'],
            MediaUploadOptions::forStandalone(
                workspaceId: (int) $workspace->getKey(),
                userId: $actor ? (int) $actor->getKey() : null,
                folderId: isset($validated['folder_id']) ? (int) $validated['folder_id'] : null,
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
            $actor,
        );

        return $this->success('Media uploaded.', ['media' => $this->mediaPayload($media)], 201);
    }

    public function show(Request $request, string $media): JsonResponse
    {
        $workspace = $this->workspace($request);
        $resolved = $this->getMedia->handle(
            $workspace,
            $this->media($workspace, $media),
            $this->actor($request),
        );

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
        $driver = $validated['driver'] ?? $validated['disk'] ?? null;
        $media = $this->remoteImports->handle(
            $workspace,
            $validated['url'],
            MediaUploadOptions::forStandalone(
                workspaceId: (int) $workspace->getKey(),
                userId: $actor ? (int) $actor->getKey() : null,
                folderId: isset($validated['folder_id']) ? (int) $validated['folder_id'] : null,
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
            $actor,
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

        $workspace = $this->workspace($request);
        $resolved = $this->updateMedia->handle(
            $workspace,
            $this->media($workspace, $media),
            $validated,
            $this->actor($request),
        );

        return $this->success('Media updated.', ['media' => $this->mediaPayload($resolved)]);
    }

    public function destroy(Request $request, string $media): JsonResponse
    {
        $workspace = $this->workspace($request);
        $this->trashMedia->handle(
            $workspace,
            $this->media($workspace, $media),
            $this->actor($request),
        );

        return $this->success('Media moved to trash.');
    }

    public function setCurrent(Request $request, string $media): JsonResponse
    {
        $workspace = $this->workspace($request);
        $selected = $this->setCurrentMedia->handle(
            $workspace,
            $this->media($workspace, $media),
            $this->actor($request),
        );

        return $this->success('Media selected as current.', ['media' => $this->mediaPayload($selected)]);
    }

    private function uploadMaxKilobytes(): int
    {
        $maxBytes = max(1, (int) config('tetranyble-storage.uploads.max_size', 50 * 1024 * 1024));

        return max(1, (int) ceil($maxBytes / 1024));
    }
}
