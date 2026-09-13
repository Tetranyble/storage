<?php

namespace Tetranyble\Storage\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Tetranyble\Storage\Modules\DirectUpload\Application\CancelDirectUpload;
use Tetranyble\Storage\Modules\DirectUpload\Application\FinalizeDirectUpload;
use Tetranyble\Storage\Modules\DirectUpload\Application\GetDirectUpload;
use Tetranyble\Storage\Modules\DirectUpload\Application\RefreshDirectUpload;
use Tetranyble\Storage\Modules\DirectUpload\Application\StartDirectUpload;
use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadProviderPlan;
use Tetranyble\Storage\Modules\DirectUpload\Application\DTO\DirectUploadRequest;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadMode;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Http\Contracts\WorkspaceContext;
use Tetranyble\Storage\Http\Routing\WorkspaceRouteResolver;

final class DirectUploadController extends StorageController
{
    public function __construct(
        WorkspaceContext $workspace,
        WorkspaceRouteResolver $routes,
        private readonly StartDirectUpload $startUpload,
        private readonly RefreshDirectUpload $refreshUpload,
        private readonly FinalizeDirectUpload $finalizeUpload,
        private readonly CancelDirectUpload $cancelUpload,
        private readonly GetDirectUpload $getUpload,
    ) {
        parent::__construct($workspace, $routes);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'original_name' => ['required', 'string', 'max:255'],
            'expected_size' => ['required', 'integer', 'min:1'],
            'sha256' => ['nullable', 'string', 'size:64', 'regex:/^[a-fA-F0-9]{64}$/'],
            'mime_type' => ['nullable', 'string', 'max:191'],
            'part_size' => ['nullable', 'integer', 'min:5242880'],
            'expires_in_minutes' => ['nullable', 'integer', 'min:5', 'max:10080'],
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
            'preserve_filename' => ['nullable', 'boolean'],
            'custom_properties' => ['nullable', 'array'],
        ]);

        $workspace = $this->workspace($request);
        $actor = $this->actor($request);
        $result = $this->startUpload->handle(
            $workspace,
            new DirectUploadRequest(
                upload: new MediaUploadOptions(
                    workspaceId: (int) $workspace->getKey(),
                    userId: $actor ? (int) $actor->getKey() : null,
                    folderId: isset($validated['folder_id']) ? (int) $validated['folder_id'] : null,
                    disk: isset($validated['disk']) ? Disk::from($validated['disk']) : null,
                    directory: (string) ($validated['directory'] ?? 'media'),
                    purpose: isset($validated['purpose']) ? MediaPurpose::from($validated['purpose']) : MediaPurpose::GENERAL,
                    label: $validated['description'] ?? null,
                    strategy: \Tetranyble\Storage\Modules\Upload\Domain\Enums\UploadStrategy::DIRECT,
                    module: (string) ($validated['module'] ?? 'media'),
                    customProperties: (array) ($validated['custom_properties'] ?? []),
                    replaceExisting: (bool) ($validated['replace_existing'] ?? false),
                    makeCurrent: (bool) ($validated['make_current'] ?? true),
                    temporary: (bool) ($validated['temporary'] ?? false),
                    preserveFilename: (bool) ($validated['preserve_filename'] ?? false),
                    originalName: $validated['original_name'],
                    attribution: $validated['attribution'] ?? null,
                ),
                expectedSize: (int) $validated['expected_size'],
                mimeType: $validated['mime_type'] ?? null,
                sha256: isset($validated['sha256']) ? strtolower($validated['sha256']) : null,
                partSize: isset($validated['part_size']) ? (int) $validated['part_size'] : null,
                expiresAt: isset($validated['expires_in_minutes'])
                    ? now()->addMinutes((int) $validated['expires_in_minutes'])
                    : null,
            ),
            $actor,
        );

        if ($result->isFallback()) {
            return $this->success('Direct upload unavailable; use the server-mediated uploader.', [
                'mode' => DirectUploadMode::FALLBACK->value,
                'reason' => $result->fallbackReason,
                'fallback' => [
                    'method' => 'POST',
                    'url' => route((string) config('tetranyble-storage.routes.name', 'tetranyble-storage.').'media.store'),
                ],
            ]);
        }

        return $this->success('Direct upload session created.', [
            'upload' => $this->sessionPayload($result->session, $result->plan),
        ], 201);
    }

    public function show(Request $request, string $directUpload): JsonResponse
    {
        $workspace = $this->workspace($request);
        $session = $this->directUploadSession($workspace, $directUpload);

        return $this->success('Direct upload loaded.', [
            'upload' => $this->getUpload->handle($workspace, $session, $this->actor($request)),
        ]);
    }

    public function refresh(Request $request, string $directUpload): JsonResponse
    {
        $validated = $request->validate([
            'parts' => ['nullable', 'array', 'max:500'],
            'parts.*' => ['integer', 'min:1'],
        ]);
        $workspace = $this->workspace($request);
        $session = $this->directUploadSession($workspace, $directUpload);
        $plan = $this->refreshUpload->handle(
            $workspace,
            $session,
            (array) ($validated['parts'] ?? []),
            $this->actor($request),
        );

        return $this->success('Direct upload instructions refreshed.', [
            'instructions' => $this->planPayload($plan),
        ]);
    }

    public function finalize(Request $request, string $directUpload): JsonResponse
    {
        $validated = $request->validate([
            'parts' => ['nullable', 'array'],
            'parts.*.part_number' => ['required_with:parts', 'integer', 'min:1'],
            'parts.*.etag' => ['required_with:parts', 'string', 'max:255'],
            'parts.*.checksum_sha256' => ['nullable', 'string', 'max:255'],
        ]);
        $workspace = $this->workspace($request);
        $session = $this->directUploadSession($workspace, $directUpload);
        $media = $this->finalizeUpload->handle(
            $workspace,
            $session,
            array_values((array) ($validated['parts'] ?? [])),
            $this->actor($request),
        );

        return $this->success('Direct upload finalized.', ['media' => $this->mediaPayload($media)]);
    }

    public function destroy(Request $request, string $directUpload): JsonResponse
    {
        $workspace = $this->workspace($request);
        $session = $this->directUploadSession($workspace, $directUpload);
        $this->cancelUpload->handle($workspace, $session, $this->actor($request));

        return $this->success('Direct upload cancelled.');
    }

    private function sessionPayload(?\Illuminate\Database\Eloquent\Model $session, ?DirectUploadProviderPlan $plan): array
    {
        return [
            'uuid' => $session?->getAttribute('uuid'),
            'mode' => $plan?->mode->value,
            'instructions' => $plan ? $this->planPayload($plan) : null,
            'expires_at' => $session?->getAttribute('session_expires_at')?->toAtomString(),
        ];
    }

    private function planPayload(DirectUploadProviderPlan $plan): array
    {
        return [
            'mode' => $plan->mode->value,
            'upload_id' => $plan->uploadId,
            'url' => $plan->url,
            'headers' => $plan->headers,
            'part_size' => $plan->partSize,
            'total_parts' => $plan->totalParts,
            'expected_size' => $plan->expectedSize,
            'parts' => array_map(static fn ($part): array => $part->toArray(), $plan->parts),
        ];
    }
}
