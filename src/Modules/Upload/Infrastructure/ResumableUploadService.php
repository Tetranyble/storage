<?php

namespace Tetranyble\Storage\Modules\Upload\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\Media\MediaDeletionService;
use Tetranyble\Storage\Modules\Observability\Domain\Contracts\StorageTelemetry;
use Tetranyble\Storage\Modules\Observability\Domain\Enums\TelemetryLevel;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\MediaUploader;
use Tetranyble\Storage\Modules\Storage\Application\DTO\IncomingFile;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\InvalidStorageOperationException;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageOrphanService;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\QuarantineStoragePolicy;
use Tetranyble\Storage\Modules\Upload\Application\DTO\UploadSessionOptions;
use Tetranyble\Storage\Modules\Upload\Domain\Aggregates\ResumableUploadLifecycle;
use Tetranyble\Storage\Modules\Upload\Domain\Enums\UploadSessionStatus;
use Tetranyble\Storage\Modules\Upload\Domain\Exceptions\IncompleteUploadSessionException;
use Tetranyble\Storage\Modules\Upload\Domain\Exceptions\UploadSessionConflictException;
use Tetranyble\Storage\Modules\Upload\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use Tetranyble\Storage\Support\StorageConfig;

class ResumableUploadService
{
    public function __construct(
        private readonly FileSystemContract $files,
        private readonly MediaUploader $mediaService,
        private readonly MediaDeletionService $deletion,
        private readonly StorageOrphanService $orphans,
        private readonly ResumableUploadOptionsCodec $optionsCodec,
        private readonly QuarantineStoragePolicy $quarantineStorage,
        private readonly ?StorageTelemetry $telemetry = null,
    ) {}

    public function startSession(UploadSessionOptions $options): UploadSession
    {
        if ($options->totalChunks < 1) {
            throw new \InvalidArgumentException('Upload sessions require at least one chunk.');
        }

        $maxUploadBytes = max(1, (int) config('tetranyble-storage.uploads.max_size', 50 * 1024 * 1024));
        if ($options->totalSize !== null && $options->totalSize > $maxUploadBytes) {
            throw new InvalidStorageOperationException(sprintf(
                'Upload session exceeds the configured maximum size (%d bytes > %d bytes).',
                $options->totalSize,
                $maxUploadBytes,
            ));
        }

        $maxChunkBytes = max(1, (int) config('tetranyble-storage.uploads.max_chunk_size', 10 * 1024 * 1024));
        if ($options->chunkSize !== null && $options->chunkSize > $maxChunkBytes) {
            throw new InvalidStorageOperationException(sprintf(
                'Upload session chunk size exceeds the configured maximum (%d bytes > %d bytes).',
                $options->chunkSize,
                $maxChunkBytes,
            ));
        }

        $fingerprint = $this->optionsCodec->fingerprint($options);
        $workspaceId = $this->optionsCodec->workspaceId($options->upload);
        $activeIdentifierHash = $this->activeIdentifierHash($workspaceId, $options->identifier);

        $existing = UploadSession::query()
            ->where('active_identifier_hash', $activeIdentifierHash)
            ->whereIn('status', $this->activeSessionStatuses())
            ->first();

        if ($existing) {
            $this->assertNoSessionConflict($existing, $fingerprint, $options);

            return $existing->refresh();
        }

        $upload = $this->optionsCodec->normalize($options->upload);
        $workspaceId = $this->optionsCodec->workspaceId($upload);
        $activeIdentifierHash = $this->activeIdentifierHash($workspaceId, $options->identifier);
        $sessionDisk = $this->optionsCodec->disk($upload);
        $this->quarantineStorage->assertStorageSafe($sessionDisk);

        try {
            return UploadSession::query()->create([
                'workspace_id' => $workspaceId,
                'user_id' => $upload->userId,
                'folder_id' => $upload->folderId,
                'identifier' => $options->identifier,
                'active_identifier_hash' => $activeIdentifierHash,
                'fingerprint' => $fingerprint,
                'original_name' => $options->originalName(),
                'mime_type' => $options->mimeType,
                'disk' => $sessionDisk->value,
                'status' => UploadSessionStatus::PENDING,
                'total_chunks' => $options->totalChunks,
                'total_size' => $options->totalSize,
                'chunk_size' => $options->chunkSize,
                'received_chunks' => 0,
                'received_bytes' => 0,
                'upload_options' => $this->optionsCodec->serialize($upload),
                'session_expires_at' => $options->expiresAt,
            ]);
        } catch (QueryException $exception) {
            // The unique active-session key closes the first-request race. If
            // another request created the same active session after our initial
            // lookup, return that canonical session instead of leaking a DB error.
            $existing = UploadSession::query()
                ->where('active_identifier_hash', $activeIdentifierHash)
                ->whereIn('status', $this->activeSessionStatuses())
                ->first();

            if (! $existing) {
                $this->recordFailure('start', null, $workspaceId, $exception);
                throw $exception;
            }

            $this->assertNoSessionConflict($existing, $fingerprint, $options);

            return $existing->refresh();
        }
    }

    public function appendChunk(
        Model $session,
        IncomingFile|UploadedFile $chunk,
        int $chunkNumber,
        ?string $checksum = null,
    ): UploadSession {
        $chunk = $chunk instanceof IncomingFile ? $chunk : new IncomingFile((string) $chunk->getRealPath(), $chunk->getClientOriginalName(), (int) ($chunk->getSize() ?? 0), $chunk->getClientMimeType() ?: null, $chunk->getMimeType() ?: null);
        $session = $this->requireUploadSession($session);
        $session = $this->freshSession($session);
        $this->assertSessionReceivesChunks($session);
        $this->assertValidChunkNumber($session, $chunkNumber);

        $checksum ??= $this->checksumForPath($chunk->localPath);
        $size = $chunk->size;
        $this->assertChunkSizeWithinConfiguredLimit($size);

        $existingChunk = $session->chunks()
            ->where('chunk_number', $chunkNumber)
            ->first();

        if ($existingChunk) {
            if ($existingChunk->getAttribute('checksum') !== $checksum || (int) $existingChunk->getAttribute('size') !== $size) {
                $this->markSessionAsConflicted(
                    $session,
                    'chunk_mismatch',
                    [
                        'chunk_number' => $chunkNumber,
                        'existing_checksum' => $existingChunk->getAttribute('checksum'),
                        'incoming_checksum' => $checksum,
                        'existing_size' => (int) $existingChunk->getAttribute('size'),
                        'incoming_size' => $size,
                    ]
                );
            }

            return $this->syncSessionProgress($session);
        }

        // Fast preflight for the normal case. The same invariant is re-checked
        // below while holding the session row lock, which is the authoritative
        // concurrency boundary for writers on a resumable session.
        $this->assertChunkWithinConfiguredLimits($session, $size);

        $disk = $this->diskForSession($session);
        $chunkFilename = $this->candidateChunkFilename($chunkNumber);
        $expectedPath = trim($this->chunkDirectory($session).'/'.$chunkFilename, '/');
        $path = null;

        try {
            $path = $this->files->disk($disk)->storeAs(
                $chunk,
                $chunkFilename,
                $this->chunkDirectory($session)
            );

            $result = DB::transaction(function () use (
                $session,
                $chunkNumber,
                $size,
                $checksum,
                $path,
            ): array {
                /** @var UploadSession $locked */
                $locked = UploadSession::query()
                    ->whereKey($session->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertLockedSessionReceivesChunks($locked);
                $this->assertValidChunkNumber($locked, $chunkNumber);

                $existing = $locked->chunks()
                    ->where('chunk_number', $chunkNumber)
                    ->first();

                if ($existing) {
                    if ($existing->getAttribute('checksum') !== $checksum || (int) $existing->getAttribute('size') !== $size) {
                        $meta = [
                            'chunk_number' => $chunkNumber,
                            'existing_checksum' => $existing->getAttribute('checksum'),
                            'incoming_checksum' => $checksum,
                            'existing_size' => (int) $existing->getAttribute('size'),
                            'incoming_size' => $size,
                        ];

                        $lifecycle = $this->lifecycle($locked);
                        $lifecycle->conflict();

                        $locked->forceFill([
                            'status' => $lifecycle->status(),
                            'active_identifier_hash' => null,
                            'conflict_reason' => 'chunk_mismatch',
                            'conflict_meta' => $meta,
                            'locked_at' => null,
                        ])->save();

                        return [
                            'session' => $locked,
                            'duplicate' => false,
                            'conflict' => $meta,
                        ];
                    }

                    $this->syncLockedSessionProgress($locked);

                    return [
                        'session' => $locked,
                        'duplicate' => true,
                        'conflict' => null,
                    ];
                }

                $this->assertChunkWithinConfiguredLimits($locked, $size);

                $locked->chunks()->create([
                    'chunk_number' => $chunkNumber,
                    'size' => $size,
                    'checksum' => $checksum,
                    'path' => $path,
                    'uploaded_at' => now(),
                ]);

                $this->syncLockedSessionProgress($locked);

                return [
                    'session' => $locked,
                    'duplicate' => false,
                    'conflict' => null,
                ];
            });

            if (($result['duplicate'] ?? false) || is_array($result['conflict'] ?? null)) {
                $this->orphans->deleteOrTrack(
                    $disk,
                    $path,
                    $session->workspace_id ? (int) $session->workspace_id : null,
                    $size > 0 ? $size : null,
                    ($result['duplicate'] ?? false)
                        ? 'upload_chunk_duplicate_candidate'
                        : 'upload_chunk_conflict_candidate',
                );
            }

            if (is_array($result['conflict'] ?? null)) {
                throw new UploadSessionConflictException(
                    'Upload session conflict detected.',
                    'chunk_mismatch',
                    $result['conflict'],
                );
            }

            /** @var UploadSession $resultSession */
            $resultSession = $result['session'];

            return $resultSession->refresh();
        } catch (\Throwable $exception) {
            $this->recordFailure('chunk', $session, $session->workspace_id ? (int) $session->workspace_id : null, $exception);
            // Candidate paths are unique per write attempt. A failed concurrent
            // writer can therefore compensate only its own object and can never
            // delete the chunk committed by another request.
            if (! isset($result) || (! ($result['duplicate'] ?? false) && ! is_array($result['conflict'] ?? null))) {
                $this->orphans->deleteOrTrack(
                    $disk,
                    is_string($path) && $path !== '' ? $path : $expectedPath,
                    $session->workspace_id ? (int) $session->workspace_id : null,
                    $size > 0 ? $size : null,
                    'upload_chunk_rollback',
                );
            }

            throw $exception;
        }
    }

    public function progress(Model $session): array
    {
        $session = $this->requireUploadSession($session);
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

    public function finalizeSession(Model $session): Media
    {
        $session = $this->requireUploadSession($session);
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

            $lifecycle = $this->lifecycle($locked);
            $lifecycle->claimAssembly();

            if ($locked->status === UploadSessionStatus::FINALIZED && $locked->media_id) {
                return $locked;
            }

            $this->assertSessionComplete($locked);

            $locked->forceFill([
                'status' => $lifecycle->status(),
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

            $options = $this->optionsCodec->hydrate($session->upload_options ?? []);
            $options = $this->optionsCodec->normalize($options);

            $uploadedFile = new IncomingFile(
                localPath: $assembledPath,
                originalName: $session->original_name ?: basename($assembledPath),
                size: (int) $assembledSize,
                clientMimeType: $session->mime_type ?: null,
                detectedMimeType: $session->mime_type ?: null,
            );

            $media = $this->mediaService->finalizeChunkedUpload($uploadedFile, $options);

            // Linking the committed Media record to its upload session is itself
            // authoritative DB state. Wrap it so an observer/DB failure cannot
            // leave a half-persisted session link.
            DB::transaction(function () use ($session, $media): void {
                $lifecycle = $this->lifecycle($session);
                $lifecycle->completeAssembly();

                $session->forceFill([
                    'status' => $lifecycle->status(),
                    'active_identifier_hash' => null,
                    'media_id' => $media->id,
                    'finalized_at' => now(),
                    'locked_at' => null,
                ])->save();
            });

            $this->purgeChunkArtifacts($session);

            return $media;
        } catch (\Throwable $exception) {
            $this->recordFailure('finalize', $session, $session->workspace_id ? (int) $session->workspace_id : null, $exception);
            $session->refresh();

            if (isset($media) && $media instanceof Media) {
                if ($session->status === UploadSessionStatus::FINALIZED
                    && (int) $session->media_id === (int) $media->id) {
                    // The link is durable; only post-commit cleanup failed. Keep
                    // the completed media and make chunk cleanup retriable.
                    $this->purgeChunkArtifacts($session);

                    return $media->refresh();
                }

                // Media persistence succeeded but the session link did not. Delete
                // the newly created media through the same compensated lifecycle
                // used by normal permanent deletion, then allow a retry.
                $this->deletion->delete($media->fresh() ?? $media);
            }

            if ($session->status !== UploadSessionStatus::CONFLICTED) {
                DB::transaction(function () use ($session): void {
                    $lifecycle = $this->lifecycle($session);
                    $lifecycle->releaseAssembly((int) $session->received_chunks > 0);

                    $session->forceFill([
                        'status' => $lifecycle->status(),
                        'active_identifier_hash' => $this->activeIdentifierHash(
                            $session->workspace_id ? (int) $session->workspace_id : null,
                            (string) $session->identifier,
                        ),
                        'locked_at' => null,
                    ])->save();
                });
            }

            throw $exception;
        } finally {
            if (is_file($assembledPath)) {
                @unlink($assembledPath);
            }
        }
    }

    public function cancelSession(Model $session): void
    {
        $session = $this->requireUploadSession($session);

        $cancelled = DB::transaction(function () use ($session): UploadSession {
            /** @var UploadSession $locked */
            $locked = UploadSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lifecycle = $this->lifecycle($locked);
            $previousStatus = $lifecycle->status();
            $lifecycle->cancel();

            if ($previousStatus !== $lifecycle->status()) {
                $locked->forceFill([
                    'status' => $lifecycle->status(),
                    'active_identifier_hash' => null,
                    'cancelled_at' => now(),
                    'locked_at' => null,
                ])->save();
            }

            return $locked;
        });

        // Physical chunks are retired only after the terminal state commits.
        $this->purgeChunkArtifacts($cancelled);
    }

    private function syncSessionProgress(UploadSession $session): UploadSession
    {
        return DB::transaction(function () use ($session): UploadSession {
            /** @var UploadSession $locked */
            $locked = UploadSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->syncLockedSessionProgress($locked);

            return $locked->refresh();
        });
    }

    private function syncLockedSessionProgress(UploadSession $session): void
    {
        $receivedChunks = (int) $session->chunks()->count();
        $receivedBytes = (int) $session->chunks()->sum('size');
        $isComplete = $receivedChunks === (int) $session->total_chunks;

        $lifecycle = $this->lifecycle($session);
        $lifecycle->synchronizeProgress($receivedChunks);

        $session->forceFill([
            'status' => $lifecycle->status(),
            'active_identifier_hash' => $this->activeIdentifierHash(
                $session->workspace_id ? (int) $session->workspace_id : null,
                (string) $session->identifier,
            ),
            'received_chunks' => $receivedChunks,
            'received_bytes' => $receivedBytes,
            'last_chunk_at' => now(),
            'completed_at' => $isComplete ? now() : null,
        ])->save();
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

    private function assertChunkSizeWithinConfiguredLimit(int $incomingBytes): void
    {
        $maxChunkBytes = max(1, (int) config(
            'tetranyble-storage.uploads.max_chunk_size',
            10 * 1024 * 1024,
        ));

        if ($incomingBytes > $maxChunkBytes) {
            throw new InvalidStorageOperationException(sprintf(
                'Upload chunk exceeds the configured maximum chunk size (%d bytes > %d bytes).',
                $incomingBytes,
                $maxChunkBytes,
            ));
        }
    }

    private function assertChunkWithinConfiguredLimits(UploadSession $session, int $incomingBytes): void
    {
        $this->assertChunkSizeWithinConfiguredLimit($incomingBytes);

        $receivedBytes = (int) $session->chunks()->sum('size');
        $candidateBytes = $receivedBytes + $incomingBytes;

        $maxUploadBytes = max(1, (int) config('tetranyble-storage.uploads.max_size', 50 * 1024 * 1024));
        if ($candidateBytes > $maxUploadBytes) {
            throw new InvalidStorageOperationException(sprintf(
                'Upload exceeds the configured maximum size (%d bytes > %d bytes).',
                $candidateBytes,
                $maxUploadBytes,
            ));
        }

        if ($session->total_size !== null && $candidateBytes > (int) $session->total_size) {
            throw new InvalidStorageOperationException(sprintf(
                'Upload exceeds the declared session size (%d bytes > %d bytes).',
                $candidateBytes,
                (int) $session->total_size,
            ));
        }
    }

    private function assertLockedSessionReceivesChunks(UploadSession $session): void
    {
        if ($session->session_expires_at && $session->session_expires_at->isPast()) {
            throw new UploadSessionConflictException(
                'Upload session has expired.',
                'session_expired',
                ['session_id' => $session->id],
            );
        }

        $this->lifecycle($session)->assertReceivesChunks();
    }

    private function assertSessionReceivesChunks(UploadSession $session): void
    {
        $this->assertSessionNotExpired($session);
        $this->lifecycle($session)->assertReceivesChunks();
    }

    private function assertSessionCanFinalize(UploadSession $session): void
    {
        $this->assertSessionNotExpired($session);
        $this->lifecycle($session)->claimAssembly();
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

        if ($session->total_size !== null) {
            $receivedBytes = (int) $session->chunks()->sum('size');
            if ($receivedBytes !== (int) $session->total_size) {
                throw new IncompleteUploadSessionException(sprintf(
                    'Upload session byte count does not match the declared total (%d bytes received, %d expected).',
                    $receivedBytes,
                    (int) $session->total_size,
                ));
            }
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
        if ($session->status === UploadSessionStatus::EXPIRED) {
            throw new UploadSessionConflictException(
                'Upload session has expired.',
                'session_expired',
                ['session_id' => $session->id]
            );
        }

        if (! $session->session_expires_at || ! $session->session_expires_at->isPast()) {
            return;
        }

        $expired = DB::transaction(function () use ($session): ?UploadSession {
            /** @var UploadSession $locked */
            $locked = UploadSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // A finalization that obtained the lock while the session was valid
            // owns the session until it commits/rolls back. The domain lifecycle
            // decides whether expiry is still a legal transition.
            $lifecycle = $this->lifecycle($locked);
            if (! $lifecycle->expire()) {
                return null;
            }

            $locked->forceFill([
                'status' => $lifecycle->status(),
                'active_identifier_hash' => null,
                'locked_at' => null,
            ])->save();

            return $locked;
        });

        if (! $expired) {
            return;
        }

        $this->purgeChunkArtifacts($expired);

        throw new UploadSessionConflictException(
            'Upload session has expired.',
            'session_expired',
            ['session_id' => $expired->id]
        );
    }

    private function markSessionAsConflicted(UploadSession $session, string $reason, array $meta): never
    {
        DB::transaction(function () use ($session, $reason, $meta): void {
            /** @var UploadSession $locked */
            $locked = UploadSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertLockedSessionReceivesChunks($locked);
            $lifecycle = $this->lifecycle($locked);
            $lifecycle->conflict();

            $locked->forceFill([
                'status' => $lifecycle->status(),
                'active_identifier_hash' => null,
                'conflict_reason' => $reason,
                'conflict_meta' => $meta,
                'locked_at' => null,
            ])->save();
        });

        throw new UploadSessionConflictException(
            'Upload session conflict detected.',
            $reason,
            $meta
        );
    }

    private function lifecycle(UploadSession $session): ResumableUploadLifecycle
    {
        return ResumableUploadLifecycle::reconstitute(
            $session->status instanceof UploadSessionStatus
                ? $session->status
                : UploadSessionStatus::from((string) $session->status),
            $session->getKey(),
        );
    }

    private function recordFailure(string $stage, ?UploadSession $session, ?int $workspaceId, \Throwable $exception): void
    {
        $dimensions = ['stage' => $stage, 'exception' => $exception::class];
        $this->telemetry?->counter('resumable_upload.failures', 1, $dimensions);
        $this->telemetry?->event('resumable_upload.failed', [
            'stage' => $stage,
            'session_id' => $session?->getKey(),
            'workspace_id' => $workspaceId,
            'exception' => $exception::class,
        ], TelemetryLevel::ERROR);
    }

    /** @return list<string> */
    private function activeSessionStatuses(): array
    {
        return [
            UploadSessionStatus::PENDING->value,
            UploadSessionStatus::UPLOADING->value,
            UploadSessionStatus::ASSEMBLING->value,
        ];
    }

    private function activeIdentifierHash(?int $workspaceId, string $identifier): string
    {
        return hash('sha256', ($workspaceId === null ? 'global' : (string) $workspaceId)."\0".$identifier);
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
                $stream = $this->files->readStream($chunk->getAttribute('path'), $disk);
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
            if (is_string($path = $chunk->getAttribute('path')) && $path !== '') {
                $this->orphans->deleteOrTrack(
                    $disk,
                    $path,
                    $session->workspace_id ? (int) $session->workspace_id : null,
                    $chunk->getAttribute('size') ? (int) $chunk->getAttribute('size') : null,
                    'upload_chunk_cleanup',
                );
            }
        }

        // Directory markers have no quota/ownership semantics and most object
        // stores do not represent them at all. Their cleanup is best effort.
        try {
            $this->files->deleteDirectory($this->sessionDirectory($session), $disk);
        } catch (\Throwable) {
            // Individual chunk objects are already removed or durably registered.
        }
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

    private function candidateChunkFilename(int $chunkNumber): string
    {
        return str_pad((string) $chunkNumber, 6, '0', STR_PAD_LEFT)
            .'-'.Str::uuid()->toString().'.part';
    }

    private function diskForSession(UploadSession $session): Disk
    {
        return is_string($session->disk) && ($disk = Disk::tryFrom($session->disk))
            ? $disk
            : StorageConfig::defaultDisk();
    }

    private function requireUploadSession(Model $session): UploadSession
    {
        if (! $session instanceof UploadSession) {
            throw new \InvalidArgumentException('Resumable upload operations require an UploadSession model.');
        }

        return $session;
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
