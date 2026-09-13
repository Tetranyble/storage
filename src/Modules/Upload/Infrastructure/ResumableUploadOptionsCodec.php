<?php

namespace Tetranyble\Storage\Modules\Upload\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaRevisionEventType;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\StoragePlacementPolicy;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Upload\Application\DTO\UploadSessionOptions;
use Tetranyble\Storage\Modules\Upload\Domain\Enums\UploadStrategy;
use Tetranyble\Storage\Support\StorageConfig;

/**
 * Stable serialization boundary for resumable upload sessions.
 *
 * Upload sessions can survive requests/processes, so their persisted options and
 * conflict fingerprint must be independent from the orchestration service itself.
 */
final class ResumableUploadOptionsCodec
{
    public function __construct(
        private readonly FileSystemContract $files,
        private readonly StoragePlacementPolicy $placement,
    ) {}

    public function fingerprint(UploadSessionOptions $options): string
    {
        $payload = [
            'identifier' => $options->identifier,
            'total_chunks' => $options->totalChunks,
            'total_size' => $options->totalSize,
            'chunk_size' => $options->chunkSize,
            'mime_type' => $options->mimeType,
            'upload' => $this->serialize($this->normalize($options->upload)),
        ];

        return hash('sha256', json_encode($this->sortRecursively($payload), JSON_UNESCAPED_SLASHES));
    }

    public function serialize(MediaUploadOptions $options): array
    {
        return [
            'model_type' => $options->model?->getMorphClass(),
            'model_id' => $options->model?->getKey(),
            'workspace_id' => $options->workspaceId,
            'user_id' => $options->userId,
            'folder_id' => $options->folderId,
            'disk' => $options->disk?->value,
            'directory' => $options->directory,
            'purpose' => $options->purpose->value,
            'label' => $options->label,
            'title' => $options->title,
            'visibility' => $options->visibility,
            'strategy' => $options->strategy->value,
            'module' => $options->module,
            'custom_properties' => $options->customProperties,
            'dispatch_post_processing' => $options->dispatchPostProcessing,
            'replace_existing' => $options->replaceExisting,
            'make_current' => $options->makeCurrent,
            'temporary' => $options->temporary,
            'expires_at' => $options->expiresAt?->format(\DateTimeInterface::ATOM),
            'preserve_filename' => $options->preserveFilename,
            'original_name' => $options->originalName,
            'attribution' => $options->attribution,
            'intended_usage' => $options->intendedUsage,
            'redirect_to' => $options->redirectTo,
            'replaces_media_id' => $options->replacesMediaId,
            'audit_event_type' => $options->auditEventType?->value,
            'audit_source_media_id' => $options->auditSourceMediaId,
            'audit_superseded_media_id' => $options->auditSupersededMediaId,
            'audit_meta' => $options->auditMeta,
            'direct_upload_session_uuid' => $options->directUploadSessionUuid,
        ];
    }

    public function hydrate(array $payload): MediaUploadOptions
    {
        $model = null;
        $modelType = $payload['model_type'] ?? null;
        $modelId = $payload['model_id'] ?? null;

        if (is_string($modelType) && $modelId !== null) {
            $modelClass = Relation::getMorphedModel($modelType) ?? $modelType;
            if (is_a($modelClass, Model::class, true)) {
                $resolved = $modelClass::query()->find($modelId);
                if ($resolved instanceof Model) {
                    $model = $resolved;
                }
            }
        }

        return new MediaUploadOptions(
            model: $model,
            workspaceId: $this->nullableInt($payload['workspace_id'] ?? null),
            userId: $this->nullableInt($payload['user_id'] ?? null),
            folderId: $this->nullableInt($payload['folder_id'] ?? null),
            disk: is_string($payload['disk'] ?? null) ? Disk::tryFrom($payload['disk']) : null,
            directory: $payload['directory'] ?? null,
            purpose: is_string($payload['purpose'] ?? null) && ($purpose = MediaPurpose::tryFrom($payload['purpose']))
                ? $purpose
                : MediaPurpose::GENERAL,
            label: $payload['label'] ?? null,
            title: $payload['title'] ?? null,
            visibility: $payload['visibility'] ?? null,
            strategy: is_string($payload['strategy'] ?? null) && ($strategy = UploadStrategy::tryFrom($payload['strategy']))
                ? $strategy
                : UploadStrategy::CHUNKED,
            module: $payload['module'] ?? null,
            customProperties: is_array($payload['custom_properties'] ?? null) ? $payload['custom_properties'] : [],
            dispatchPostProcessing: (bool) ($payload['dispatch_post_processing'] ?? false),
            replaceExisting: (bool) ($payload['replace_existing'] ?? false),
            makeCurrent: (bool) ($payload['make_current'] ?? true),
            temporary: (bool) ($payload['temporary'] ?? false),
            expiresAt: ! empty($payload['expires_at']) ? Carbon::parse($payload['expires_at']) : null,
            preserveFilename: (bool) ($payload['preserve_filename'] ?? false),
            originalName: $payload['original_name'] ?? null,
            attribution: $payload['attribution'] ?? null,
            intendedUsage: $payload['intended_usage'] ?? null,
            redirectTo: $payload['redirect_to'] ?? null,
            replacesMediaId: $this->nullableInt($payload['replaces_media_id'] ?? null),
            auditEventType: is_string($payload['audit_event_type'] ?? null)
                ? MediaRevisionEventType::tryFrom($payload['audit_event_type'])
                : null,
            auditSourceMediaId: $this->nullableInt($payload['audit_source_media_id'] ?? null),
            auditSupersededMediaId: $this->nullableInt($payload['audit_superseded_media_id'] ?? null),
            auditMeta: is_array($payload['audit_meta'] ?? null) ? $payload['audit_meta'] : [],
            directUploadSessionUuid: is_string($payload['direct_upload_session_uuid'] ?? null) ? $payload['direct_upload_session_uuid'] : null,
        );
    }

    public function normalize(MediaUploadOptions $options): MediaUploadOptions
    {
        return new MediaUploadOptions(
            model: $options->model,
            workspaceId: $options->workspaceId,
            userId: $options->userId,
            folderId: $options->folderId,
            disk: $options->disk,
            directory: $options->directory,
            purpose: $options->purpose,
            label: $options->label,
            title: $options->title,
            visibility: $options->visibility,
            strategy: UploadStrategy::CHUNKED,
            module: $options->module,
            customProperties: $options->customProperties,
            dispatchPostProcessing: $options->dispatchPostProcessing,
            replaceExisting: $options->replaceExisting,
            makeCurrent: $options->makeCurrent,
            temporary: $options->temporary,
            expiresAt: $options->expiresAt,
            preserveFilename: $options->preserveFilename,
            originalName: $options->originalName,
            attribution: $options->attribution,
            intendedUsage: $options->intendedUsage,
            redirectTo: $options->redirectTo,
            replacesMediaId: $options->replacesMediaId,
            auditEventType: $options->auditEventType,
            auditSourceMediaId: $options->auditSourceMediaId,
            auditSupersededMediaId: $options->auditSupersededMediaId,
            auditMeta: $options->auditMeta,
            directUploadSessionUuid: $options->directUploadSessionUuid,
        );
    }

    public function workspaceId(MediaUploadOptions $options): ?int
    {
        if ($options->model) {
            return StorageConfig::resolveWorkspaceFromModel($options->model)?->id;
        }

        return $options->workspaceId ? (int) $options->workspaceId : null;
    }

    public function disk(MediaUploadOptions $options): Disk
    {
        if ($options->disk) {
            return $options->disk;
        }

        return $this->placement->preferredDisk($options) ?? $this->files->getDefaultDisk();
    }

    private function sortRecursively(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sortRecursively($value);
            }
        }

        ksort($payload);

        return $payload;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
