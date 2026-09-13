<?php

namespace Tetranyble\Storage\Modules\DirectUpload\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Contracts\DirectUploadGateway;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Aggregates\DirectUploadLifecycle;
use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadObject;
use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadPart;
use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadProviderPlan;
use Tetranyble\Storage\Modules\DirectUpload\Application\DTO\DirectUploadRequest;
use Tetranyble\Storage\Modules\DirectUpload\Application\DTO\DirectUploadStartResult;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadMode;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadStatus;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Exceptions\DirectUploadConflictException;
use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\InvalidStorageOperationException;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Upload\Domain\Enums\UploadStrategy;
use Tetranyble\Storage\Modules\DirectUpload\Infrastructure\Persistence\Eloquent\Models\DirectUploadSession;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\Media\MediaStoragePathResolver;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\MediaService;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageOrphanService;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageService;
use Tetranyble\Storage\Modules\Upload\Infrastructure\ResumableUploadOptionsCodec;
use Tetranyble\Storage\Support\StorageConfig;
use Tetranyble\Storage\Modules\Observability\Domain\Contracts\StorageTelemetry;
use Tetranyble\Storage\Modules\Observability\Domain\Enums\TelemetryLevel;

final class DirectUploadService
{
    private const S3_MIN_PART_SIZE = 5 * 1024 * 1024;
    private const S3_MAX_PARTS = 10_000;

    public function __construct(
        private readonly DirectUploadGateway $gateway,
        private readonly StorageService $storage,
        private readonly FileSystemContract $files,
        private readonly StorageOrphanService $orphans,
        private readonly MediaStoragePathResolver $paths,
        private readonly MediaService $media,
        private readonly ResumableUploadOptionsCodec $optionsCodec,
        private readonly DirectUploadRequestValidator $requestValidator,
        private readonly ?StorageTelemetry $telemetry = null,
    ) {}

    public function start(DirectUploadRequest $request): DirectUploadStartResult
    {
        $this->requestValidator->assertValid($request);
        $options = $this->directOptions($request->upload);
        $workspace = $this->workspace($options);
        $disk = $this->paths->disk($options);

        if (! $this->isEnabled() || ! $this->gateway->supports($disk)) {
            if ((bool) config('tetranyble-storage.direct_uploads.fallback_to_server', true)) {
                return new DirectUploadStartResult(
                    mode: DirectUploadMode::FALLBACK,
                    fallbackReason: ! $this->isEnabled()
                        ? 'Direct uploads are disabled by package configuration.'
                        : sprintf('Direct uploads are not available for disk [%s].', $disk->value),
                );
            }

            throw new InvalidStorageOperationException(sprintf(
                'Direct uploads are unavailable for disk [%s].',
                $disk->value,
            ));
        }

        $sessionUuid = (string) Str::uuid();
        $originalName = trim((string) ($options->originalName ?? '')) ?: 'upload.bin';
        $directory = $this->paths->uploadDirectory($options, $workspace);
        $filename = $this->paths->storedFilename($originalName, $options->preserveFilename);
        $objectKey = trim($directory.'/direct/'.$sessionUuid.'/'.$filename, '/');
        $expiresAt = $request->expiresAt
            ? \Illuminate\Support\Carbon::instance(\DateTimeImmutable::createFromInterface($request->expiresAt))
            : now()->addMinutes($this->sessionTtlMinutes());
        $urlTtl = max(60, min($this->urlTtlSeconds(), max(60, now()->diffInSeconds($expiresAt, false))));
        $partSize = $this->resolvePartSize($request->partSize);

        // Persist the quota reservation and a recoverable PENDING session in one
        // database transaction before contacting the provider. A worker crash
        // between reservation and provider setup therefore leaves durable state
        // that storage:cleanup-direct-uploads can expire and reconcile later.
        /** @var DirectUploadSession $session */
        $session = DB::transaction(function () use (
            $workspace,
            $request,
            $sessionUuid,
            $options,
            $disk,
            $objectKey,
            $originalName,
            $expiresAt,
        ): DirectUploadSession {
            $this->storage->increaseUsage($workspace, $request->expectedSize);

            return DirectUploadSession::query()->create([
                'uuid' => $sessionUuid,
                'workspace_id' => $workspace->getKey(),
                'user_id' => $options->userId,
                'folder_id' => $options->folderId,
                'provider' => 's3',
                'mode' => null,
                'status' => DirectUploadStatus::PENDING,
                'disk' => $disk,
                'object_key' => $objectKey,
                'object_key_hash' => hash('sha256', $disk->value."\0".$objectKey),
                'original_name' => $originalName,
                'mime_type' => $request->mimeType,
                'expected_size' => $request->expectedSize,
                'expected_sha256' => $request->sha256 ? strtolower($request->sha256) : null,
                'reserved_bytes' => $request->expectedSize,
                'upload_options' => $this->optionsCodec->serialize($options),
                'session_expires_at' => $expiresAt,
            ]);
        });

        $plan = null;
        try {
            $plan = $this->gateway->begin(
                disk: $disk,
                objectKey: $objectKey,
                expectedSize: $request->expectedSize,
                mimeType: $request->mimeType,
                sha256: $request->sha256 ? strtolower($request->sha256) : null,
                expiresInSeconds: $urlTtl,
                multipartThreshold: $this->multipartThreshold(),
                partSize: $partSize,
                initialPartCount: $this->initialPartCount(),
            );

            if ($plan->mode === DirectUploadMode::MULTIPART
                && (($plan->totalParts ?? 0) < 1 || ($plan->totalParts ?? 0) > self::S3_MAX_PARTS)) {
                throw new InvalidStorageOperationException(sprintf(
                    'Direct multipart upload requires %d parts; S3-compatible uploads support at most %d.',
                    (int) ($plan->totalParts ?? 0),
                    self::S3_MAX_PARTS,
                ));
            }

            $lifecycle = $this->lifecycle($session);
            $lifecycle->beginUploading();

            $session->forceFill([
                'mode' => $plan->mode,
                'status' => $lifecycle->status(),
                'provider_upload_id' => $plan->uploadId,
                'part_size' => $plan->partSize,
                'total_parts' => $plan->totalParts,
                'failure_reason' => null,
            ])->save();

            return new DirectUploadStartResult(
                mode: $plan->mode,
                session: $session,
                plan: $plan,
            );
        } catch (Throwable $exception) {
            $this->failStart($session, $plan, $exception);
            throw $exception;
        }
    }

    public function refresh(Model $session, array $partNumbers = []): DirectUploadProviderPlan
    {
        $session = $this->typed($session);
        $this->assertActive($session);
        $ttl = $this->urlTtlSeconds();
        $disk = $this->sessionDisk($session);

        if ($session->mode === DirectUploadMode::SINGLE) {
            return $this->gateway->signSingle(
                $disk,
                (string) $session->object_key,
                $session->mime_type,
                $session->expected_sha256,
                $ttl,
                (int) $session->expected_size,
            );
        }

        $parts = $partNumbers === []
            ? range(1, min((int) $session->total_parts, $this->initialPartCount()))
            : $this->validatedPartNumbers($session, $partNumbers);

        return new DirectUploadProviderPlan(
            mode: DirectUploadMode::MULTIPART,
            uploadId: (string) $session->provider_upload_id,
            parts: $this->signParts($session, $parts),
            partSize: (int) $session->part_size,
            totalParts: (int) $session->total_parts,
            expectedSize: (int) $session->expected_size,
        );
    }

    public function signParts(Model $session, array $partNumbers): array
    {
        $session = $this->typed($session);
        $this->assertActive($session);
        if ($session->mode !== DirectUploadMode::MULTIPART || ! $session->provider_upload_id) {
            throw new DirectUploadConflictException('This direct-upload session is not multipart.', 'not_multipart');
        }

        $partNumbers = $this->validatedPartNumbers($session, $partNumbers);

        return $this->gateway->signParts(
            $this->sessionDisk($session),
            (string) $session->object_key,
            (string) $session->provider_upload_id,
            $partNumbers,
            $this->urlTtlSeconds(),
            (int) $session->expected_size,
            (int) $session->part_size,
        );
    }

    public function finalize(Model $session, array $parts = []): Model
    {
        $session = $this->claimForFinalization($this->typed($session));

        if ($session->media_id) {
            $media = Media::query()->find($session->media_id);
            if ($media instanceof Media) {
                return $media;
            }
        }

        try {
            $disk = $this->sessionDisk($session);

            if ($session->mode === DirectUploadMode::MULTIPART) {
                $validatedParts = $this->validatedCompletionParts($session, $parts);

                if (! $this->objectExists($session)) {
                    $this->gateway->completeMultipart(
                        $disk,
                        (string) $session->object_key,
                        (string) $session->provider_upload_id,
                        $validatedParts,
                    );
                }
            }

            $object = $this->gateway->inspect($disk, (string) $session->object_key);
            $this->assertObjectIntegrity($session, $object);
            $checksum = $this->verifiedChecksum($session, $object);
            $workspace = $this->workspaceForSession($session);
            $options = $this->directOptions(
                $this->optionsCodec->hydrate((array) $session->upload_options),
                (string) $session->uuid,
            );

            $media = $this->media->registerDirectUploadObject(
                storedPath: (string) $session->object_key,
                size: (int) $session->expected_size,
                mime: $object->mimeType?->value ?? $session->mime_type,
                originalName: (string) $session->original_name,
                checksum: $checksum,
                options: $options,
                workspace: $workspace,
                disk: $disk,
                sessionUuid: (string) $session->uuid,
            );

            DB::transaction(function () use ($session, $media): void {
                /** @var DirectUploadSession $locked */
                $locked = DirectUploadSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
                $lifecycle = $this->lifecycle($locked);
                $lifecycle->completeFinalization();

                $locked->forceFill([
                    'media_id' => $media->getKey(),
                    'status' => $lifecycle->status(),
                    'reserved_bytes' => $lifecycle->reservedBytes(),
                    'finalized_at' => now(),
                    'finalizing_at' => null,
                    'failure_reason' => null,
                    'cleanup_pending' => false,
                ])->save();
            });

            return $media;
        } catch (DirectUploadConflictException $exception) {
            if (in_array($exception->reason, ['size_mismatch', 'checksum_mismatch'], true)) {
                $this->failAndRelease($session, $exception->getMessage());
            } else {
                $this->releaseFinalizationClaim($session, $exception->getMessage());
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->releaseFinalizationClaim($session, $exception->getMessage());
            throw $exception;
        }
    }

    public function cancel(Model $session): void
    {
        $session = $this->typed($session);
        $workspace = $this->workspaceForSession($session);

        DB::transaction(function () use ($session, $workspace): void {
            /** @var DirectUploadSession $locked */
            $locked = DirectUploadSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            $lifecycle = $this->lifecycle($locked);
            $previousStatus = $lifecycle->status();
            $reserved = $lifecycle->cancel();

            if ($previousStatus === $lifecycle->status()) {
                return;
            }

            $locked->forceFill([
                'status' => $lifecycle->status(),
                'reserved_bytes' => $lifecycle->reservedBytes(),
                'cancelled_at' => now(),
                'cleanup_pending' => true,
            ])->save();

            if ($reserved > 0) {
                $this->storage->decreaseUsage($workspace, $reserved);
            }
        });

        $session->refresh();
        $this->cleanup($session);
    }

    public function progress(Model $session): array
    {
        $session = $this->typed($session);

        return [
            'uuid' => $session->uuid,
            'status' => $session->status?->value ?? (string) $session->status,
            'mode' => $session->mode?->value ?? (string) $session->mode,
            'disk' => $session->disk?->value ?? (string) $session->disk,
            // Provider object keys are internal placement details; clients only
            // need signed instructions and session state, not storage paths.
            'original_name' => $session->original_name,
            'mime_type' => $session->mime_type,
            'expected_size' => (int) $session->expected_size,
            'expected_sha256' => $session->expected_sha256,
            'part_size' => $session->part_size === null ? null : (int) $session->part_size,
            'total_parts' => $session->total_parts === null ? null : (int) $session->total_parts,
            'expires_at' => $session->session_expires_at?->toAtomString(),
            'media_id' => $session->media_id,
            'cleanup_pending' => (bool) $session->cleanup_pending,
            'failure_reason' => $session->failure_reason,
        ];
    }

    public function cleanupExpired(int $limit = 100): array
    {
        $limit = max(1, min($limit, 1000));
        $expired = 0;
        $cleaned = 0;
        $failed = 0;

        $ids = DirectUploadSession::query()
            ->whereIn('status', [DirectUploadStatus::PENDING->value, DirectUploadStatus::UPLOADING->value])
            ->whereNotNull('session_expires_at')
            ->where('session_expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            /** @var DirectUploadSession|null $session */
            $session = DirectUploadSession::query()->find($id);
            if (! $session) {
                continue;
            }

            if ($this->expire($session)) {
                $expired++;
            }
        }

        $cleanupIds = DirectUploadSession::query()
            ->where('cleanup_pending', true)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($cleanupIds as $id) {
            /** @var DirectUploadSession|null $session */
            $session = DirectUploadSession::query()->find($id);
            if (! $session) {
                continue;
            }

            if ($this->cleanup($session)) {
                $cleaned++;
            } else {
                $failed++;
            }
        }

        return compact('expired', 'cleaned', 'failed');
    }

    private function claimForFinalization(DirectUploadSession $session): DirectUploadSession
    {
        $this->expireIfNeeded($session);

        return DB::transaction(function () use ($session): DirectUploadSession {
            /** @var DirectUploadSession $locked */
            $locked = DirectUploadSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            $lifecycle = $this->lifecycle($locked);
            $previousStatus = $lifecycle->status();
            $lifecycle->claimFinalization();

            if ($previousStatus === DirectUploadStatus::FINALIZED) {
                return $locked;
            }

            $locked->forceFill([
                'status' => $lifecycle->status(),
                'finalizing_at' => now(),
                'failure_reason' => null,
            ])->save();

            return $locked;
        });
    }

    private function assertActive(DirectUploadSession $session): void
    {
        $this->expireIfNeeded($session);

        $this->lifecycle($session)->assertWritable();
    }

    private function expireIfNeeded(DirectUploadSession $session): void
    {
        if ($session->session_expires_at && $session->session_expires_at->isPast()
            && in_array($session->status, [DirectUploadStatus::PENDING, DirectUploadStatus::UPLOADING], true)) {
            $this->expire($session);
            throw new DirectUploadConflictException('Direct upload session has expired.', 'expired');
        }
    }

    private function expire(DirectUploadSession $session): bool
    {
        $workspace = $this->workspaceForSession($session);
        $changed = DB::transaction(function () use ($session, $workspace): bool {
            /** @var DirectUploadSession $locked */
            $locked = DirectUploadSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            $lifecycle = $this->lifecycle($locked);
            $previousStatus = $lifecycle->status();
            $reserved = $lifecycle->expire();
            if ($previousStatus === $lifecycle->status()) {
                return false;
            }

            $locked->forceFill([
                'status' => $lifecycle->status(),
                'reserved_bytes' => $lifecycle->reservedBytes(),
                'cleanup_pending' => true,
                'failure_reason' => 'Direct upload session expired before finalization.',
            ])->save();

            if ($reserved > 0) {
                $this->storage->decreaseUsage($workspace, $reserved);
            }

            return true;
        });

        if ($changed) {
            $session->refresh();
            $this->cleanup($session);
        }

        return $changed;
    }

    private function failStart(
        DirectUploadSession $session,
        ?DirectUploadProviderPlan $plan,
        Throwable $exception,
    ): void {
        $this->recordFailure($session, 'start_failed', $exception::class);
        $workspace = $this->workspaceForSession($session);
        $persisted = false;

        try {
            DB::transaction(function () use ($session, $workspace, $plan, $exception): void {
                /** @var DirectUploadSession $locked */
                $locked = DirectUploadSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
                $lifecycle = $this->lifecycle($locked);
                $reserved = $lifecycle->fail();

                $locked->forceFill([
                    'mode' => $plan?->mode ?? $locked->mode,
                    'provider_upload_id' => $plan?->uploadId ?? $locked->provider_upload_id,
                    'part_size' => $plan?->partSize ?? $locked->part_size,
                    'total_parts' => $plan?->totalParts ?? $locked->total_parts,
                    'status' => $lifecycle->status(),
                    'reserved_bytes' => $lifecycle->reservedBytes(),
                    'failed_at' => now(),
                    'failure_reason' => mb_substr($exception->getMessage(), 0, 2000),
                    'cleanup_pending' => $plan?->mode === DirectUploadMode::MULTIPART && $plan->uploadId !== null,
                ])->save();

                if ($reserved > 0) {
                    $this->storage->decreaseUsage($workspace, $reserved);
                }
            });
            $persisted = true;
        } catch (Throwable) {
            // The durable PENDING session and reservation remain recoverable by
            // expiry cleanup if the database itself is temporarily unavailable.
        }

        if ($persisted) {
            $session->refresh();
            if ($session->cleanup_pending) {
                $this->cleanup($session);
            }

            return;
        }

        // If persistence failed after provider initialization, make one best-effort
        // provider abort. We intentionally do not independently decrement quota here:
        // doing so without a matching session state change could double-release once
        // the durable PENDING session is later expired.
        if ($plan?->mode === DirectUploadMode::MULTIPART && $plan->uploadId) {
            try {
                $this->gateway->abortMultipart(
                    $this->sessionDisk($session),
                    (string) $session->object_key,
                    $plan->uploadId,
                );
            } catch (Throwable) {
                // Expiry recovery remains the authoritative fallback.
            }
        }
    }

    private function releaseFinalizationClaim(DirectUploadSession $session, string $reason): void
    {
        DB::transaction(function () use ($session, $reason): void {
            /** @var DirectUploadSession|null $locked */
            $locked = DirectUploadSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof DirectUploadSession) {
                return;
            }

            $lifecycle = $this->lifecycle($locked);
            $previousStatus = $lifecycle->status();
            $lifecycle->releaseFinalization();
            if ($previousStatus === $lifecycle->status()) {
                return;
            }

            $locked->forceFill([
                'status' => $lifecycle->status(),
                'finalizing_at' => null,
                'failure_reason' => mb_substr($reason, 0, 2000),
            ])->save();
        });
    }

    private function failAndRelease(DirectUploadSession $session, string $reason): void
    {
        $this->recordFailure($session, 'integrity_or_finalization_failed', 'direct_upload_failure');
        $workspace = $this->workspaceForSession($session);

        DB::transaction(function () use ($session, $workspace, $reason): void {
            /** @var DirectUploadSession $locked */
            $locked = DirectUploadSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            $lifecycle = $this->lifecycle($locked);
            $previousStatus = $lifecycle->status();
            $reserved = $lifecycle->fail();
            if ($previousStatus === DirectUploadStatus::FINALIZED) {
                return;
            }

            $locked->forceFill([
                'status' => $lifecycle->status(),
                'reserved_bytes' => $lifecycle->reservedBytes(),
                'failed_at' => now(),
                'finalizing_at' => null,
                'failure_reason' => mb_substr($reason, 0, 2000),
                'cleanup_pending' => true,
            ])->save();

            if ($reserved > 0) {
                $this->storage->decreaseUsage($workspace, $reserved);
            }
        });

        $session->refresh();
        $this->cleanup($session);
    }

    private function cleanup(DirectUploadSession $session): bool
    {
        $multipartClean = true;
        $errors = [];

        if ($session->mode === DirectUploadMode::MULTIPART && $session->provider_upload_id) {
            try {
                $this->gateway->abortMultipart(
                    $this->sessionDisk($session),
                    (string) $session->object_key,
                    (string) $session->provider_upload_id,
                );
            } catch (Throwable $exception) {
                // A completed multipart upload no longer has an upload id. If the
                // final object exists, object cleanup below is sufficient.
                if (! $this->objectExists($session)) {
                    $multipartClean = false;
                    $errors[] = $exception->getMessage();
                }
            }
        }

        $this->orphans->deleteOrTrack(
            $this->sessionDisk($session),
            (string) $session->object_key,
            $session->workspace_id === null ? null : (int) $session->workspace_id,
            (int) $session->expected_size,
            'direct_upload_cleanup',
        );

        $session->forceFill([
            'cleanup_pending' => ! $multipartClean,
            'cleanup_attempts' => ((int) $session->cleanup_attempts) + 1,
            'cleanup_error' => $multipartClean ? null : mb_substr(implode('; ', $errors), 0, 2000),
            'cleanup_attempted_at' => now(),
        ])->save();

        if (! $multipartClean) {
            $this->telemetry?->counter('direct_upload.cleanup_failures', 1, ['provider' => (string) $session->provider]);
            $this->telemetry?->event('direct_upload.cleanup_failed', [
                'session_id' => (int) $session->getKey(),
                'workspace_id' => $session->workspace_id !== null ? (int) $session->workspace_id : null,
                'provider' => (string) $session->provider,
                'cleanup_attempts' => (int) $session->cleanup_attempts,
            ], TelemetryLevel::ERROR);
        }

        return $multipartClean;
    }

    private function lifecycle(DirectUploadSession $session): DirectUploadLifecycle
    {
        return DirectUploadLifecycle::reconstitute(
            $session->status instanceof DirectUploadStatus
                ? $session->status
                : DirectUploadStatus::from((string) $session->status),
            (int) $session->reserved_bytes,
        );
    }

    private function recordFailure(DirectUploadSession $session, string $reason, string $exception): void
    {
        $dimensions = ['provider' => (string) $session->provider, 'reason' => $reason];
        $this->telemetry?->counter('direct_upload.failures', 1, $dimensions);
        $this->telemetry?->event('direct_upload.failed', [
            'session_id' => (int) $session->getKey(),
            'workspace_id' => $session->workspace_id !== null ? (int) $session->workspace_id : null,
            'provider' => (string) $session->provider,
            'mode' => $session->mode?->value ?? null,
            'reason' => $reason,
            'exception' => $exception,
        ], TelemetryLevel::ERROR);
    }

    private function assertObjectIntegrity(DirectUploadSession $session, DirectUploadObject $object): void
    {
        if ($object->size->bytes !== (int) $session->expected_size) {
            throw new DirectUploadConflictException(sprintf(
                'Direct upload size mismatch (%d bytes stored, %d expected).',
                $object->size->bytes,
                (int) $session->expected_size,
            ), 'size_mismatch');
        }
    }

    private function verifiedChecksum(DirectUploadSession $session, DirectUploadObject $object): ?string
    {
        $expected = is_string($session->expected_sha256) && $session->expected_sha256 !== ''
            ? strtolower($session->expected_sha256)
            : null;

        if ($expected === null) {
            return $object->sha256?->value;
        }

        if ($object->sha256 !== null) {
            if (! hash_equals($expected, $object->sha256->value)) {
                throw new DirectUploadConflictException('Direct upload SHA-256 checksum mismatch.', 'checksum_mismatch');
            }

            return $expected;
        }

        if (! (bool) config('tetranyble-storage.direct_uploads.stream_checksum_fallback', true)) {
            throw new DirectUploadConflictException(
                'The storage provider did not expose a full-object SHA-256 checksum and streamed verification is disabled.',
                'checksum_unverifiable',
            );
        }

        $max = max(1, (int) config(
            'tetranyble-storage.direct_uploads.max_stream_checksum_bytes',
            1024 * 1024 * 1024,
        ));
        if ((int) $session->expected_size > $max) {
            throw new DirectUploadConflictException(sprintf(
                'Direct upload requires streamed SHA-256 verification but the object exceeds the configured verification ceiling (%d > %d bytes).',
                (int) $session->expected_size,
                $max,
            ), 'checksum_unverifiable');
        }

        $actual = $this->streamSha256((string) $session->object_key, $this->sessionDisk($session));
        if (! hash_equals($expected, $actual)) {
            throw new DirectUploadConflictException('Direct upload SHA-256 checksum mismatch.', 'checksum_mismatch');
        }

        return $expected;
    }

    private function streamSha256(string $path, Disk $disk): string
    {
        $stream = $this->files->readStream($path, $disk);
        if (! is_resource($stream) && ! $stream instanceof StreamInterface) {
            throw new RuntimeException('Unable to open direct-upload object for checksum verification.');
        }

        $hash = hash_init('sha256');
        try {
            if (is_resource($stream)) {
                while (! feof($stream)) {
                    $chunk = fread($stream, 1024 * 1024);
                    if ($chunk === false) {
                        throw new RuntimeException('Failed while reading direct-upload object for checksum verification.');
                    }
                    if ($chunk !== '') {
                        hash_update($hash, $chunk);
                    }
                }
            } else {
                while (! $stream->eof()) {
                    $chunk = $stream->read(1024 * 1024);
                    if ($chunk !== '') {
                        hash_update($hash, $chunk);
                    }
                }
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            } elseif ($stream instanceof StreamInterface) {
                $stream->close();
            }
        }

        return hash_final($hash);
    }

    private function validatedPartNumbers(DirectUploadSession $session, array $partNumbers): array
    {
        $maxPerRequest = max(1, min(500, (int) config('tetranyble-storage.direct_uploads.max_signed_parts_per_request', 100)));
        $numbers = array_values(array_unique(array_map('intval', $partNumbers)));
        sort($numbers);

        if ($numbers === [] || count($numbers) > $maxPerRequest) {
            throw new InvalidStorageOperationException(sprintf(
                'Request between 1 and %d direct-upload part URLs at a time.',
                $maxPerRequest,
            ));
        }

        $total = (int) $session->total_parts;
        foreach ($numbers as $number) {
            if ($number < 1 || $number > $total) {
                throw new InvalidStorageOperationException(sprintf(
                    'Direct-upload part %d is outside the valid range 1..%d.',
                    $number,
                    $total,
                ));
            }
        }

        return $numbers;
    }

    private function validatedCompletionParts(DirectUploadSession $session, array $parts): array
    {
        $total = (int) $session->total_parts;
        if (count($parts) !== $total) {
            throw new DirectUploadConflictException(sprintf(
                'Multipart finalization requires exactly %d completed parts.',
                $total,
            ), 'incomplete_parts');
        }

        usort($parts, static fn (array $a, array $b): int => ((int) ($a['part_number'] ?? 0)) <=> ((int) ($b['part_number'] ?? 0)));

        foreach ($parts as $index => $part) {
            $expectedNumber = $index + 1;
            if ((int) ($part['part_number'] ?? 0) !== $expectedNumber || trim((string) ($part['etag'] ?? '')) === '') {
                throw new DirectUploadConflictException(
                    'Multipart completion parts must be consecutive and include a non-empty ETag.',
                    'invalid_parts',
                );
            }
        }

        return $parts;
    }

    private function objectExists(DirectUploadSession $session): bool
    {
        try {
            return $this->files->exists((string) $session->object_key, $this->sessionDisk($session));
        } catch (Throwable) {
            return false;
        }
    }

    private function resolvePartSize(?int $requested): int
    {
        $configured = max(self::S3_MIN_PART_SIZE, (int) config(
            'tetranyble-storage.direct_uploads.part_size',
            8 * 1024 * 1024,
        ));
        $partSize = $requested ?? $configured;

        if ($partSize < self::S3_MIN_PART_SIZE) {
            throw new InvalidStorageOperationException(sprintf(
                'S3 multipart part size must be at least %d bytes.',
                self::S3_MIN_PART_SIZE,
            ));
        }

        return $partSize;
    }

    private function multipartThreshold(): int
    {
        return max(self::S3_MIN_PART_SIZE, (int) config(
            'tetranyble-storage.direct_uploads.multipart_threshold',
            32 * 1024 * 1024,
        ));
    }

    private function initialPartCount(): int
    {
        return max(0, min(100, (int) config('tetranyble-storage.direct_uploads.initial_signed_parts', 5)));
    }

    private function urlTtlSeconds(): int
    {
        return max(60, min(604800, (int) config('tetranyble-storage.direct_uploads.url_ttl_seconds', 900)));
    }

    private function sessionTtlMinutes(): int
    {
        return max(5, (int) config('tetranyble-storage.direct_uploads.session_ttl_minutes', 60));
    }

    private function isEnabled(): bool
    {
        return (bool) config('tetranyble-storage.direct_uploads.enabled', false);
    }

    private function workspace(MediaUploadOptions $options): Model
    {
        if ($options->model) {
            $workspace = StorageConfig::resolveWorkspaceFromModel($options->model);
        } else {
            $workspace = StorageConfig::findWorkspace($options->workspaceId);
        }

        if (! $workspace instanceof Model) {
            throw new InvalidStorageOperationException('Direct uploads require a valid workspace so quota can be reserved.');
        }

        return $workspace;
    }

    private function workspaceForSession(DirectUploadSession $session): Model
    {
        $workspace = StorageConfig::findWorkspace($session->workspace_id);
        if (! $workspace instanceof Model) {
            throw new RuntimeException('Direct-upload workspace no longer exists.');
        }

        return $workspace;
    }

    private function directOptions(MediaUploadOptions $options, ?string $sessionUuid = null): MediaUploadOptions
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
            strategy: UploadStrategy::DIRECT,
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
            directUploadSessionUuid: $sessionUuid ?? $options->directUploadSessionUuid,
        );
    }

    private function sessionDisk(DirectUploadSession $session): Disk
    {
        if ($session->disk instanceof Disk) {
            return $session->disk;
        }

        $disk = Disk::tryFrom((string) $session->disk);
        if (! $disk) {
            throw new RuntimeException('Direct-upload session references an unknown storage disk.');
        }

        return $disk;
    }

    private function typed(Model $session): DirectUploadSession
    {
        if (! $session instanceof DirectUploadSession) {
            throw new InvalidStorageOperationException('Invalid direct-upload session model.');
        }

        return $session;
    }
}
