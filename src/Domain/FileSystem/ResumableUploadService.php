<?php

namespace Tetranyble\Storage\Domain\FileSystem;

use Tetranyble\Storage\Contracts\ResumableUploadManager;
use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Domain\FileSystem\DTO\UploadSessionOptions;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\FileSystem\Enums\UploadSessionStatus;
use Tetranyble\Storage\Domain\FileSystem\Enums\UploadStrategy;
use Tetranyble\Storage\Domain\FileSystem\Exceptions\IncompleteUploadSessionException;
use Tetranyble\Storage\Domain\FileSystem\Exceptions\UploadSessionConflictException;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Enums\MediaRevisionEventType;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\UploadSession;
use Tetranyble\Storage\Models\UploadSessionChunk;
use Tetranyble\Storage\Support\StorageConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResumableUploadService implements ResumableUploadManager
{
    public function __construct(
        private readonly FileSystemContract $files,
        private readonly MediaService $mediaService,
    ) {}

    public function startSession(UploadSessionOptions $options): UploadSession
    {
        if ($options->totalChunks < 1) {
            throw new \InvalidArgumentException('Upload sessions require at least one chunk.');
        }

        $fingerprint = $this->fingerprintForOptions($options);
        $workspaceId = $this->resolveWorkspaceIdFromUpload($options->upload);

        $existing = UploadSession::query()
            ->when(
                $workspaceId === null,
                fn ($query) => $query->whereNull('workspace_id'),
                fn ($query) => $query->where('workspace_id', $workspaceId)
            )
            ->where('identifier', $options->identifier)
            ->whereIn('status', [
                UploadSessionStatus::PENDING->value,
                UploadSessionStatus::UPLOADING->value,
                UploadSessionStatus::ASSEMBLING->value,
            ])
            ->latest('id')
            ->first();

        if ($existing) {
            $this->assertNoSessionConflict($existing, $fingerprint, $options);

            return $existing->refresh();
        }

        $upload = $this->normalizeUploadOptions($options->upload);

        return UploadSession::query()->create([
            'workspace_id' => $this->resolveWorkspaceIdFromUpload($upload),
            'user_id' => $upload->userId,
            'folder_id' => $upload->folderId,
            'identifier' => $options->identifier,
            'fingerprint' => $fingerprint,
            'original_name' => $options->originalName(),
            'mime_type' => $options->mimeType,
            'disk' => $this->resolveDisk($upload)->value,
            'status' => UploadSessionStatus::PENDING,
            'total_chunks' => $options->totalChunks,
            'total_size' => $options->totalSize,
            'chunk_size' => $options->chunkSize,
            'received_chunks' => 0,
            'received_bytes' => 0,
            'upload_options' => $this->serializeUploadOptions($upload),
            'session_expires_at' => $options->expiresAt,
        ]);
    }

    public function appendChunk(
        UploadSession $session,
        UploadedFile $chunk,
        int $chunkNumber,
        ?string $checksum = null,
    ): UploadSession {
        $session = $this->freshSession($session);
        $this->assertSessionReceivesChunks($session);
        $this->assertValidChunkNumber($session, $chunkNumber);

        $checksum ??= $this->checksumForPath($chunk->getRealPath());
        $size = (int) ($chunk->getSize() ?? 0);

        $existingChunk = $session->chunks()
            ->where('chunk_number', $chunkNumber)
            ->first();

        if ($existingChunk) {
            if ($existingChunk->checksum !== $checksum || (int) $existingChunk->size !== $size) {
                $this->markSessionAsConflicted(
                    $session,
                    'chunk_mismatch',
                    [
                        'chunk_number' => $chunkNumber,
                        'existing_checksum' => $existingChunk->checksum,
                        'incoming_checksum' => $checksum,
                        'existing_size' => (int) $existingChunk->size,
                        'incoming_size' => $size,
                    ]
                );
            }

            return $this->syncSessionProgress($session);
        }

        $disk = $this->diskForSession($session);
        $path = $this->files->disk($disk)->storeAs(
            $chunk,
            $this->chunkFilename($chunkNumber),
            $this->chunkDirectory($session)
        );

        $session->chunks()->create([
            'chunk_number' => $chunkNumber,
            'size' => $size,
            'checksum' => $checksum,
            'path' => $path,
            'uploaded_at' => now(),
        ]);

        return $this->syncSessionProgress($session);
    }

    public function progress(UploadSession $session): array
    {
        $session = $this->freshSession($session);
        $receivedChunkNumbers = $session->chunks()
            ->orderBy('chunk_number')
            ->pluck('chunk_number')
            ->map(fn ($chunkNumber) => (int) $chunkNumber)
            ->all();

        $missingChunkNumbers = array_values(array_diff(
            range(1, (int) $session->total_chunks),
            $receivedChunkNumbers
        ));

        $receivedChunks = count($receivedChunkNumbers);
        $percentage = $session->total_chunks > 0
            ? (int) floor(($receivedChunks / $session->total_chunks) * 100)
            : 0;

        return [
            'session_id' => $session->id,
            'uuid' => $session->uuid,
            'identifier' => $session->identifier,
            'status' => $session->status?->value ?? UploadSessionStatus::PENDING->value,
            'received_chunks' => $receivedChunks,
            'total_chunks' => (int) $session->total_chunks,
            'received_bytes' => (int) $session->received_bytes,
            'total_size' => $session->total_size !== null ? (int) $session->total_size : null,
            'percentage' => $percentage,
            'finished' => $receivedChunks === (int) $session->total_chunks && $missingChunkNumbers === [],
            'missing_chunks' => $missingChunkNumbers,
            'media_id' => $session->media_id,
            'completed_at' => optional($session->completed_at)?->toIso8601String(),
            'finalized_at' => optional($session->finalized_at)?->toIso8601String(),
            'conflict_reason' => $session->conflict_reason,
            'conflict_meta' => is_array($session->conflict_meta) ? $session->conflict_meta : [],
        ];
    }

    public function finalizeSession(UploadSession $session): Media
    {
        $session = $this->freshSession($session);

        if ($session->status === UploadSessionStatus::FINALIZED && $session->media_id) {
            $media = Media::query()->find($session->media_id);
            if ($media instanceof Media) {
                return $media;
            }
        }

        $this->assertSessionCanFinalize($session);

        $session = DB::transaction(function () use ($session): UploadSession {
            /** @var UploadSession $locked */
            $locked = UploadSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === UploadSessionStatus::ASSEMBLING) {
                throw new UploadSessionConflictException(
                    'Upload session is already being finalized.',
                    'session_locked',
                    ['session_id' => $locked->id]
                );
            }

            if ($locked->status === UploadSessionStatus::FINALIZED && $locked->media_id) {
                return $locked;
            }

            $this->assertSessionComplete($locked);

            $locked->forceFill([
                'status' => UploadSessionStatus::ASSEMBLING,
                'locked_at' => now(),
            ])->save();

            return $locked;
        });

        if ($session->status === UploadSessionStatus::FINALIZED && $session->media_id) {
            /** @var Media $media */
            $media = Media::query()->findOrFail($session->media_id);

            return $media;
        }

        $assembledPath = tempnam(sys_get_temp_dir(), 'tetranyble-upload-');
        if ($assembledPath === false) {
            throw new \RuntimeException('Unable to create a temporary file for upload assembly.');
        }

        try {
            $this->assembleChunksIntoFile($session, $assembledPath);
            $assembledSize = filesize($assembledPath) ?: 0;

            if ($session->total_size !== null && $assembledSize !== (int) $session->total_size) {
                $this->markSessionAsConflicted(
                    $session,
                    'assembled_size_mismatch',
                    [
                        'expected_size' => (int) $session->total_size,
                        'actual_size' => (int) $assembledSize,
                    ]
                );
            }

            $options = $this->hydrateUploadOptions($session->upload_options ?? []);
            $options = $this->normalizeUploadOptions($options);

            $uploadedFile = new UploadedFile(
                $assembledPath,
                $session->original_name ?: basename($assembledPath),
                $session->mime_type ?: 'application/octet-stream',
                null,
                true
            );

            $media = $this->mediaService->finalizeChunkedUpload($uploadedFile, $options);

            $session->forceFill([
                'status' => UploadSessionStatus::FINALIZED,
                'media_id' => $media->id,
                'finalized_at' => now(),
                'locked_at' => null,
            ])->save();

            $this->purgeChunkArtifacts($session);

            return $media;
        } catch (\Throwable $exception) {
            $session->refresh();

            if ($session->status !== UploadSessionStatus::CONFLICTED) {
                $session->forceFill([
                    'status' => $session->received_chunks > 0 ? UploadSessionStatus::UPLOADING : UploadSessionStatus::PENDING,
                    'locked_at' => null,
                ])->save();
            }

            throw $exception;
        } finally {
            if (is_file($assembledPath)) {
                @unlink($assembledPath);
            }
        }
    }

    public function cancelSession(UploadSession $session): void
    {
        $session = $this->freshSession($session);

        if ($session->status === UploadSessionStatus::FINALIZED) {
            throw new UploadSessionConflictException(
                'Finalized upload sessions cannot be cancelled.',
                'session_finalized',
                ['session_id' => $session->id]
            );
        }

        $this->purgeChunkArtifacts($session);

        $session->forceFill([
            'status' => UploadSessionStatus::CANCELLED,
            'cancelled_at' => now(),
            'locked_at' => null,
        ])->save();
    }

    private function syncSessionProgress(UploadSession $session): UploadSession
    {
        $receivedChunks = (int) $session->chunks()->count();
        $receivedBytes = (int) $session->chunks()->sum('size');
        $isComplete = $receivedChunks === (int) $session->total_chunks;

        $session->forceFill([
            'status' => $receivedChunks > 0 ? UploadSessionStatus::UPLOADING : UploadSessionStatus::PENDING,
            'received_chunks' => $receivedChunks,
            'received_bytes' => $receivedBytes,
            'last_chunk_at' => now(),
            'completed_at' => $isComplete ? now() : null,
        ])->save();

        return $session->refresh();
    }

    private function assertNoSessionConflict(
        UploadSession $session,
        string $fingerprint,
        UploadSessionOptions $options,
    ): void {
        if ($session->fingerprint === $fingerprint) {
            return;
        }

        throw new UploadSessionConflictException(
            'Upload session identifier is already in use for different upload metadata.',
            'session_metadata_mismatch',
            [
                'session_id' => $session->id,
                'identifier' => $options->identifier,
            ]
        );
    }

    private function assertSessionReceivesChunks(UploadSession $session): void
    {
        $this->assertSessionNotExpired($session);

        if ($session->status === UploadSessionStatus::CONFLICTED) {
            throw new UploadSessionConflictException(
                'Upload session is conflicted and cannot receive more chunks.',
                'session_conflicted',
                ['session_id' => $session->id]
            );
        }

        if ($session->status === UploadSessionStatus::CANCELLED) {
            throw new UploadSessionConflictException(
                'Upload session has been cancelled.',
                'session_cancelled',
                ['session_id' => $session->id]
            );
        }

        if ($session->status === UploadSessionStatus::FINALIZED) {
            throw new UploadSessionConflictException(
                'Upload session has already been finalized.',
                'session_finalized',
                ['session_id' => $session->id]
            );
        }
    }

    private function assertSessionCanFinalize(UploadSession $session): void
    {
        $this->assertSessionNotExpired($session);

        if ($session->status === UploadSessionStatus::CONFLICTED) {
            throw new UploadSessionConflictException(
                'Conflicted upload sessions cannot be finalized.',
                'session_conflicted',
                ['session_id' => $session->id]
            );
        }

        if ($session->status === UploadSessionStatus::CANCELLED) {
            throw new UploadSessionConflictException(
                'Cancelled upload sessions cannot be finalized.',
                'session_cancelled',
                ['session_id' => $session->id]
            );
        }
    }

    private function assertSessionComplete(UploadSession $session): void
    {
        $receivedChunkNumbers = $session->chunks()
            ->pluck('chunk_number')
            ->map(fn ($chunkNumber) => (int) $chunkNumber)
            ->all();
        $missing = array_values(array_diff(
            range(1, (int) $session->total_chunks),
            $receivedChunkNumbers
        ));

        if ($missing !== []) {
            throw new IncompleteUploadSessionException(
                'Upload session cannot be finalized until all chunks are present.'
            );
        }
    }

    private function assertValidChunkNumber(UploadSession $session, int $chunkNumber): void
    {
        if ($chunkNumber < 1 || $chunkNumber > (int) $session->total_chunks) {
            throw new UploadSessionConflictException(
                'Chunk number is outside the declared upload session range.',
                'invalid_chunk_number',
                [
                    'chunk_number' => $chunkNumber,
                    'total_chunks' => (int) $session->total_chunks,
                ]
            );
        }
    }

    private function assertSessionNotExpired(UploadSession $session): void
    {
        if ($session->session_expires_at && $session->session_expires_at->isPast()) {
            $session->forceFill([
                'status' => UploadSessionStatus::EXPIRED,
                'locked_at' => null,
            ])->save();

            throw new UploadSessionConflictException(
                'Upload session has expired.',
                'session_expired',
                ['session_id' => $session->id]
            );
        }
    }

    private function markSessionAsConflicted(UploadSession $session, string $reason, array $meta): never
    {
        $session->forceFill([
            'status' => UploadSessionStatus::CONFLICTED,
            'conflict_reason' => $reason,
            'conflict_meta' => $meta,
            'locked_at' => null,
        ])->save();

        throw new UploadSessionConflictException(
            'Upload session conflict detected.',
            $reason,
            $meta
        );
    }

    private function assembleChunksIntoFile(UploadSession $session, string $assembledPath): void
    {
        $handle = fopen($assembledPath, 'wb');
        if (! is_resource($handle)) {
            throw new \RuntimeException('Unable to open assembled upload file for writing.');
        }

        try {
            $disk = $this->diskForSession($session);
            $chunks = $session->chunks()
                ->orderBy('chunk_number')
                ->get();

            foreach ($chunks as $chunk) {
                $stream = $this->files->readStream($chunk->path, $disk);
                if (! is_resource($stream)) {
                    throw new \RuntimeException('Unable to read upload chunk from storage.');
                }

                try {
                    stream_copy_to_stream($stream, $handle);
                } finally {
                    fclose($stream);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function purgeChunkArtifacts(UploadSession $session): void
    {
        $disk = $this->diskForSession($session);

        foreach ($session->chunks()->get() as $chunk) {
            if ($chunk->path) {
                $this->files->delete($chunk->path, $disk);
            }
        }

        $this->files->deleteDirectory($this->sessionDirectory($session), $disk);
    }

    private function chunkDirectory(UploadSession $session): string
    {
        return trim($this->sessionDirectory($session).'/chunks', '/');
    }

    private function sessionDirectory(UploadSession $session): string
    {
        $workspaceSegment = $session->workspace_id ? (string) $session->workspace_id : 'global';

        return "tetranyble-storage/uploads/{$workspaceSegment}/{$session->uuid}";
    }

    private function chunkFilename(int $chunkNumber): string
    {
        return str_pad((string) $chunkNumber, 6, '0', STR_PAD_LEFT).'.part';
    }

    private function diskForSession(UploadSession $session): Disk
    {
        return is_string($session->disk) && ($disk = Disk::tryFrom($session->disk))
            ? $disk
            : Disk::default();
    }

    private function fingerprintForOptions(UploadSessionOptions $options): string
    {
        $payload = [
            'identifier' => $options->identifier,
            'total_chunks' => $options->totalChunks,
            'total_size' => $options->totalSize,
            'chunk_size' => $options->chunkSize,
            'mime_type' => $options->mimeType,
            'upload' => $this->serializeUploadOptions($this->normalizeUploadOptions($options->upload)),
        ];

        return hash('sha256', json_encode($this->sortRecursively($payload), JSON_UNESCAPED_SLASHES));
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

    private function serializeUploadOptions(MediaUploadOptions $options): array
    {
        return [
            'model_type' => $options->model ? get_class($options->model) : null,
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
        ];
    }

    private function hydrateUploadOptions(array $payload): MediaUploadOptions
    {
        $model = null;
        $modelType = $payload['model_type'] ?? null;
        $modelId = $payload['model_id'] ?? null;

        if (is_string($modelType) && $modelId !== null && is_a($modelType, Model::class, true)) {
            $resolved = $modelType::query()->find($modelId);
            if ($resolved instanceof Model) {
                $model = $resolved;
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
        );
    }

    private function normalizeUploadOptions(MediaUploadOptions $options): MediaUploadOptions
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
        );
    }

    private function resolveWorkspaceIdFromUpload(MediaUploadOptions $options): ?int
    {
        if ($options->model) {
            $workspace = $this->resolveWorkspaceFromModel($options->model);

            return $workspace?->id;
        }

        return $options->workspaceId ? (int) $options->workspaceId : null;
    }

    private function resolveWorkspaceFromModel(Model $model): ?Model
    {
        return StorageConfig::resolveWorkspaceFromModel($model);
    }

    private function resolveDisk(MediaUploadOptions $options): Disk
    {
        if ($options->disk) {
            return $options->disk;
        }

        return match (true) {
            in_array($options->module, ['payslip', 'statement', 'file-centre'], true) => Disk::PRIVATE,
            in_array($options->purpose, [
                MediaPurpose::IMPORT_SOURCE,
                MediaPurpose::BANK_STATEMENT,
                MediaPurpose::LOAN_SUPPORTING_DOCUMENT,
                MediaPurpose::IDENTITY_DOCUMENT_FRONT,
                MediaPurpose::IDENTITY_DOCUMENT_BACK,
                MediaPurpose::BOARD_RESOLUTION,
                MediaPurpose::BUSINESS_LICENSE,
                MediaPurpose::MEMORANDUM_ARTICLES,
                MediaPurpose::NEXT_OF_KIN_ID,
            ], true) => Disk::PRIVATE,
            default => $this->files->getDefaultDisk(),
        };
    }

    private function freshSession(UploadSession $session): UploadSession
    {
        return UploadSession::query()->with('chunks')->findOrFail($session->id);
    }

    private function checksumForPath(?string $path): ?string
    {
        return $path && is_file($path) ? hash_file('sha256', $path) : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
