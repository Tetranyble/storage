<?php

namespace Tetranyble\Storage\Tests\Unit;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\InvalidStorageOperationException;
use Tetranyble\Storage\Modules\Upload\Application\Contracts\ResumableUploadManager;
use Tetranyble\Storage\Modules\Upload\Application\DTO\UploadSessionOptions;
use Tetranyble\Storage\Modules\Upload\Domain\Enums\UploadSessionStatus;
use Tetranyble\Storage\Modules\Upload\Domain\Enums\UploadStrategy;
use Tetranyble\Storage\Modules\Upload\Domain\Exceptions\IncompleteUploadSessionException;
use Tetranyble\Storage\Modules\Upload\Domain\Exceptions\UploadSessionConflictException;
use Tetranyble\Storage\Modules\Upload\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use Tetranyble\Storage\Modules\Upload\Infrastructure\Persistence\Eloquent\Models\UploadSessionChunk;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;

class ResumableUploadServiceTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_resumable_session_rejects_public_chunk_storage_when_quarantine_is_enabled(): void
    {
        config()->set('tetranyble-storage.trust.virus_scanning.enabled', true);
        config()->set('tetranyble-storage.trust.quarantine_until_clean', true);
        config()->set('tetranyble-storage.trust.require_private_storage', true);

        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(ResumableUploadManager::class);

        $this->expectException(InvalidStorageOperationException::class);
        $service->startSession(new UploadSessionOptions(
            identifier: 'public-quarantine',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                disk: Disk::PUBLIC,
                originalName: 'unsafe.pdf',
            ),
            totalChunks: 1,
            totalSize: 10,
            mimeType: 'application/pdf',
        ));
    }

    public function test_resumable_upload_session_tracks_progress_and_finalizes_into_media(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(ResumableUploadManager::class);

        $session = $service->startSession(new UploadSessionOptions(
            identifier: 'statement-2026-06',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::DOCUMENT,
                directory: 'statements',
                module: 'statement',
                strategy: UploadStrategy::CHUNKED,
                temporary: false,
                originalName: 'statement.pdf',
                attribution: 'chunk-upload',
            ),
            totalChunks: 2,
            totalSize: strlen('hello world'),
            mimeType: 'application/pdf',
        ));

        $service->appendChunk(
            $session,
            UploadedFile::fake()->createWithContent('chunk-1.part', 'hello '),
            1
        );

        $progress = $service->progress($session);

        $this->assertSame(50, $progress['percentage']);
        $this->assertSame([2], $progress['missing_chunks']);
        $this->assertFalse($progress['finished']);

        $session = $service->appendChunk(
            $session,
            UploadedFile::fake()->createWithContent('chunk-2.part', 'world'),
            2
        );

        $progress = $service->progress($session);

        $this->assertTrue($progress['finished']);
        $this->assertSame(2, $progress['received_chunks']);

        $chunkPaths = $session->chunks()->orderBy('chunk_number')->pluck('path')->all();
        $media = $service->finalizeSession($session);

        $this->assertSame(UploadStrategy::CHUNKED, $media->upload_strategy);
        $this->assertSame('statement.pdf', $media->original_name);
        $this->assertSame($workspace->id, $media->workspace_id);
        Storage::disk('local')->assertExists($media->path);

        foreach ($chunkPaths as $path) {
            Storage::disk('local')->assertMissing($path);
        }

        $this->assertDatabaseHas('upload_sessions', [
            'id' => $session->id,
            'media_id' => $media->id,
            'status' => UploadSessionStatus::FINALIZED->value,
        ]);
    }

    public function test_resumable_service_rejects_oversized_declared_session_without_application_or_http_guard(): void
    {
        config()->set('tetranyble-storage.uploads.max_size', 100);

        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(ResumableUploadManager::class);

        $this->expectException(InvalidStorageOperationException::class);
        $this->expectExceptionMessage('configured maximum size');

        $service->startSession(new UploadSessionOptions(
            identifier: 'direct-service-oversized-session',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::GENERAL,
                strategy: UploadStrategy::CHUNKED,
                originalName: 'large.bin',
            ),
            totalChunks: 1,
            totalSize: 101,
            mimeType: 'application/octet-stream',
        ));
    }

    public function test_resumable_service_rejects_declared_chunk_size_over_configured_limit(): void
    {
        config()->set('tetranyble-storage.uploads.max_size', 100);
        config()->set('tetranyble-storage.uploads.max_chunk_size', 10);

        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(ResumableUploadManager::class);

        $this->expectException(InvalidStorageOperationException::class);
        $this->expectExceptionMessage('chunk size exceeds the configured maximum');

        $service->startSession(new UploadSessionOptions(
            identifier: 'direct-service-oversized-chunk-declaration',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::GENERAL,
                strategy: UploadStrategy::CHUNKED,
                originalName: 'large.bin',
            ),
            totalChunks: 2,
            totalSize: 20,
            chunkSize: 11,
            mimeType: 'application/octet-stream',
        ));
    }

    public function test_resumable_service_rejects_actual_chunk_over_chunk_limit_before_storage(): void
    {
        config()->set('tetranyble-storage.uploads.max_size', 100);
        config()->set('tetranyble-storage.uploads.max_chunk_size', 4);

        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(ResumableUploadManager::class);
        $session = $service->startSession(new UploadSessionOptions(
            identifier: 'direct-service-actual-chunk-limit',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::GENERAL,
                strategy: UploadStrategy::CHUNKED,
                originalName: 'large.bin',
            ),
            totalChunks: 1,
            totalSize: 5,
            mimeType: 'application/octet-stream',
        ));

        try {
            $service->appendChunk(
                $session,
                UploadedFile::fake()->createWithContent('chunk.part', '12345'),
                1,
            );
            $this->fail('Oversized chunk should be rejected before it is stored.');
        } catch (InvalidStorageOperationException $exception) {
            $this->assertStringContainsString('maximum chunk size', $exception->getMessage());
        }

        $this->assertSame(0, UploadSessionChunk::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_resumable_service_enforces_cumulative_maximum_when_total_size_is_unknown(): void
    {
        config()->set('tetranyble-storage.uploads.max_size', 5);
        config()->set('tetranyble-storage.uploads.max_chunk_size', 5);

        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(ResumableUploadManager::class);
        $session = $service->startSession(new UploadSessionOptions(
            identifier: 'direct-service-cumulative-limit',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::GENERAL,
                strategy: UploadStrategy::CHUNKED,
                originalName: 'unknown-size.bin',
            ),
            totalChunks: 2,
            totalSize: null,
            mimeType: 'application/octet-stream',
        ));

        $session = $service->appendChunk(
            $session,
            UploadedFile::fake()->createWithContent('chunk-1.part', '123'),
            1,
        );

        try {
            $service->appendChunk(
                $session,
                UploadedFile::fake()->createWithContent('chunk-2.part', '456'),
                2,
            );
            $this->fail('Cumulative upload bytes should enforce the configured maximum.');
        } catch (InvalidStorageOperationException $exception) {
            $this->assertStringContainsString('configured maximum size', $exception->getMessage());
        }

        $this->assertSame(1, UploadSessionChunk::query()->count());
        $this->assertSame(3, (int) $session->fresh()->received_bytes);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_resumable_service_rejects_chunks_that_exceed_declared_total_size(): void
    {
        config()->set('tetranyble-storage.uploads.max_size', 100);
        config()->set('tetranyble-storage.uploads.max_chunk_size', 100);

        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(ResumableUploadManager::class);
        $session = $service->startSession(new UploadSessionOptions(
            identifier: 'declared-total-limit',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::GENERAL,
                strategy: UploadStrategy::CHUNKED,
                originalName: 'declared.bin',
            ),
            totalChunks: 2,
            totalSize: 5,
            mimeType: 'application/octet-stream',
        ));

        $session = $service->appendChunk(
            $session,
            UploadedFile::fake()->createWithContent('chunk-1.part', '123'),
            1,
        );

        try {
            $service->appendChunk(
                $session,
                UploadedFile::fake()->createWithContent('chunk-2.part', '456'),
                2,
            );
            $this->fail('Chunks must not exceed the declared upload-session byte count.');
        } catch (InvalidStorageOperationException $exception) {
            $this->assertStringContainsString('declared session size', $exception->getMessage());
        }

        $this->assertSame(1, UploadSessionChunk::query()->count());
        $this->assertSame(3, (int) $session->fresh()->received_bytes);
    }

    public function test_resumable_service_refuses_chunks_once_finalization_has_locked_the_session(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(ResumableUploadManager::class);
        $session = $service->startSession(new UploadSessionOptions(
            identifier: 'assembling-session',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::GENERAL,
                strategy: UploadStrategy::CHUNKED,
                originalName: 'assembling.bin',
            ),
            totalChunks: 1,
            totalSize: 1,
            mimeType: 'application/octet-stream',
        ));
        $session->forceFill([
            'status' => UploadSessionStatus::ASSEMBLING,
            'locked_at' => now(),
        ])->save();

        try {
            $service->appendChunk(
                $session,
                UploadedFile::fake()->createWithContent('chunk.part', '1'),
                1,
            );
            $this->fail('An assembling upload session must be immutable to chunk writers.');
        } catch (UploadSessionConflictException $exception) {
            $this->assertSame('session_locked', $exception->reason);
        }

        $this->assertSame(0, UploadSessionChunk::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_finalization_rejects_complete_chunk_count_with_wrong_declared_byte_total(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(ResumableUploadManager::class);
        $session = $service->startSession(new UploadSessionOptions(
            identifier: 'declared-total-finalize-mismatch',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::GENERAL,
                strategy: UploadStrategy::CHUNKED,
                originalName: 'declared.bin',
            ),
            totalChunks: 1,
            totalSize: 5,
            mimeType: 'application/octet-stream',
        ));

        $session = $service->appendChunk(
            $session,
            UploadedFile::fake()->createWithContent('chunk.part', '1234'),
            1,
        );

        $this->expectException(IncompleteUploadSessionException::class);
        $this->expectExceptionMessage('byte count does not match the declared total');

        $service->finalizeSession($session);
    }

    public function test_cancelling_assembling_session_is_rejected_without_deleting_chunks(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(ResumableUploadManager::class);
        $session = $service->startSession(new UploadSessionOptions(
            identifier: 'cancel-assembling-session',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::GENERAL,
                strategy: UploadStrategy::CHUNKED,
                originalName: 'assembling.bin',
            ),
            totalChunks: 2,
            totalSize: 2,
            mimeType: 'application/octet-stream',
        ));
        $session = $service->appendChunk(
            $session,
            UploadedFile::fake()->createWithContent('chunk-1.part', '1'),
            1,
        );
        $chunkPath = (string) $session->chunks()->firstOrFail()->path;
        $session->forceFill([
            'status' => UploadSessionStatus::ASSEMBLING,
            'locked_at' => now(),
        ])->save();

        try {
            $service->cancelSession($session);
            $this->fail('An assembling session must not be cancellable underneath finalization.');
        } catch (UploadSessionConflictException $exception) {
            $this->assertSame('session_locked', $exception->reason);
        }

        $this->assertSame(UploadSessionStatus::ASSEMBLING, $session->fresh()->status);
        Storage::disk('local')->assertExists($chunkPath);
        $this->assertDatabaseHas('upload_session_chunks', [
            'upload_session_id' => $session->id,
            'chunk_number' => 1,
        ]);
    }

    public function test_expiry_check_does_not_rewrite_a_finalized_session(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(ResumableUploadManager::class);
        $session = $service->startSession(new UploadSessionOptions(
            identifier: 'finalized-expiry-race',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::GENERAL,
                strategy: UploadStrategy::CHUNKED,
                originalName: 'finalized.bin',
            ),
            totalChunks: 1,
            totalSize: 1,
            mimeType: 'application/octet-stream',
            expiresAt: now()->subMinute(),
        ));
        $session->forceFill([
            'status' => UploadSessionStatus::FINALIZED,
            'active_identifier_hash' => null,
            'finalized_at' => now(),
        ])->save();

        try {
            $service->appendChunk(
                $session,
                UploadedFile::fake()->createWithContent('chunk.part', '1'),
                1,
            );
            $this->fail('Finalized sessions must reject subsequent chunks.');
        } catch (UploadSessionConflictException $exception) {
            $this->assertSame('session_finalized', $exception->reason);
        }

        $this->assertSame(UploadSessionStatus::FINALIZED, $session->fresh()->status);
    }

    public function test_starting_same_active_session_twice_returns_the_canonical_session(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(ResumableUploadManager::class);
        $options = new UploadSessionOptions(
            identifier: 'same-active-session',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::GENERAL,
                strategy: UploadStrategy::CHUNKED,
                originalName: 'notes.txt',
            ),
            totalChunks: 2,
            totalSize: 10,
            mimeType: 'text/plain',
        );

        $first = $service->startSession($options);
        $second = $service->startSession($options);

        $this->assertSame($first->id, $second->id);
        $this->assertNotNull($first->fresh()->active_identifier_hash);
        $this->assertSame(1, UploadSession::query()->count());
    }

    public function test_terminal_session_releases_identifier_for_a_new_upload(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(ResumableUploadManager::class);
        $options = new UploadSessionOptions(
            identifier: 'reusable-session-id',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::GENERAL,
                strategy: UploadStrategy::CHUNKED,
                originalName: 'notes.txt',
            ),
            totalChunks: 1,
            totalSize: 5,
            mimeType: 'text/plain',
        );

        $first = $service->startSession($options);
        $service->cancelSession($first);
        $this->assertNull($first->fresh()->active_identifier_hash);

        $second = $service->startSession($options);

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotNull($second->active_identifier_hash);
    }

    public function test_starting_same_identifier_with_conflicting_metadata_throws_conflict(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(ResumableUploadManager::class);

        $service->startSession(new UploadSessionOptions(
            identifier: 'duplicate-id',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::GENERAL,
                directory: 'workspace',
                module: 'file-centre',
                strategy: UploadStrategy::CHUNKED,
                temporary: false,
                originalName: 'brief.txt',
            ),
            totalChunks: 2,
            totalSize: 10,
            mimeType: 'text/plain',
        ));

        $this->expectException(UploadSessionConflictException::class);

        $service->startSession(new UploadSessionOptions(
            identifier: 'duplicate-id',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::GENERAL,
                directory: 'workspace',
                module: 'file-centre',
                strategy: UploadStrategy::CHUNKED,
                temporary: false,
                originalName: 'other.txt',
            ),
            totalChunks: 2,
            totalSize: 10,
            mimeType: 'text/plain',
        ));
    }

    public function test_duplicate_chunk_is_idempotent_but_conflicting_retry_marks_session_conflicted(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(ResumableUploadManager::class);

        $session = $service->startSession(new UploadSessionOptions(
            identifier: 'idempotent-retry',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::GENERAL,
                directory: 'workspace',
                module: 'file-centre',
                strategy: UploadStrategy::CHUNKED,
                temporary: false,
                originalName: 'notes.txt',
            ),
            totalChunks: 2,
            totalSize: 10,
            mimeType: 'text/plain',
        ));

        $session = $service->appendChunk(
            $session,
            UploadedFile::fake()->createWithContent('chunk-1.part', 'same'),
            1
        );

        $session = $service->appendChunk(
            $session,
            UploadedFile::fake()->createWithContent('chunk-1-duplicate.part', 'same'),
            1
        );

        $this->assertSame(1, $session->received_chunks);

        try {
            $service->appendChunk(
                $session,
                UploadedFile::fake()->createWithContent('chunk-1-conflict.part', 'different'),
                1
            );
            $this->fail('Conflicting chunk retry should raise an exception.');
        } catch (UploadSessionConflictException $exception) {
            $this->assertSame('chunk_mismatch', $exception->reason);
        }

        $this->assertDatabaseHas('upload_sessions', [
            'id' => $session->id,
            'status' => UploadSessionStatus::CONFLICTED->value,
            'conflict_reason' => 'chunk_mismatch',
        ]);
    }

    public function test_chunk_storage_is_compensated_when_chunk_row_persistence_fails(): void
    {
        $workspace = Workspace::create(['name' => 'Chunk rollback']);
        $service = $this->app->make(ResumableUploadManager::class);
        $session = $service->startSession(new UploadSessionOptions(
            identifier: 'chunk-db-failure',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::GENERAL,
                directory: 'workspace',
                module: 'file-centre',
                strategy: UploadStrategy::CHUNKED,
                temporary: false,
                originalName: 'notes.txt',
            ),
            totalChunks: 1,
            totalSize: 5,
            mimeType: 'text/plain',
        ));

        $event = 'eloquent.creating: '.UploadSessionChunk::class;
        Event::listen($event, static function (): never {
            throw new RuntimeException('forced chunk persistence failure');
        });

        try {
            $service->appendChunk(
                $session,
                UploadedFile::fake()->createWithContent('chunk-1.part', 'hello'),
                1,
            );
            $this->fail('The forced chunk persistence failure should have escaped.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced chunk persistence failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseMissing('upload_session_chunks', [
            'upload_session_id' => $session->id,
            'chunk_number' => 1,
        ]);
        $this->assertSame(0, $session->fresh()->received_chunks);
    }

    public function test_failed_cancellation_persistence_keeps_chunks_and_session_resumable(): void
    {
        $workspace = Workspace::create(['name' => 'Cancel rollback']);
        $service = $this->app->make(ResumableUploadManager::class);
        $session = $service->startSession(new UploadSessionOptions(
            identifier: 'cancel-db-failure',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::GENERAL,
                directory: 'workspace',
                module: 'file-centre',
                strategy: UploadStrategy::CHUNKED,
                temporary: false,
                originalName: 'notes.txt',
            ),
            totalChunks: 2,
            totalSize: 10,
            mimeType: 'text/plain',
        ));
        $session = $service->appendChunk(
            $session,
            UploadedFile::fake()->createWithContent('chunk-1.part', 'hello'),
            1,
        );
        $chunkPath = (string) $session->chunks()->firstOrFail()->path;

        $event = 'eloquent.updating: '.UploadSession::class;
        Event::listen($event, static function (UploadSession $candidate): void {
            if ($candidate->isDirty('status') && $candidate->status === UploadSessionStatus::CANCELLED) {
                throw new RuntimeException('forced cancellation persistence failure');
            }
        });

        try {
            $service->cancelSession($session);
            $this->fail('The forced cancellation persistence failure should have escaped.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced cancellation persistence failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $fresh = $session->fresh();
        $this->assertSame(UploadSessionStatus::UPLOADING, $fresh->status);
        $this->assertNull($fresh->cancelled_at);
        Storage::disk('local')->assertExists($chunkPath);
        $this->assertDatabaseHas('upload_session_chunks', [
            'upload_session_id' => $session->id,
            'chunk_number' => 1,
        ]);
    }

    public function test_failed_final_session_link_compensates_created_media_and_preserves_chunks_for_retry(): void
    {
        $workspace = Workspace::create([
            'name' => 'Finalize rollback',
            'storage_quota_bytes' => 1024 * 1024,
            'storage_used_bytes' => 0,
        ]);
        $service = $this->app->make(ResumableUploadManager::class);
        $session = $service->startSession(new UploadSessionOptions(
            identifier: 'final-link-db-failure',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::GENERAL,
                directory: 'workspace',
                module: 'file-centre',
                strategy: UploadStrategy::CHUNKED,
                temporary: false,
                originalName: 'notes.txt',
            ),
            totalChunks: 1,
            totalSize: 5,
            mimeType: 'text/plain',
        ));
        $session = $service->appendChunk(
            $session,
            UploadedFile::fake()->createWithContent('chunk-1.part', 'hello'),
            1,
        );
        $chunkPath = (string) $session->chunks()->firstOrFail()->path;

        $event = 'eloquent.updating: '.UploadSession::class;
        Event::listen($event, static function (UploadSession $candidate): void {
            if ($candidate->isDirty('media_id') && $candidate->media_id !== null) {
                throw new RuntimeException('forced final session link failure');
            }
        });

        try {
            $service->finalizeSession($session);
            $this->fail('The forced final session link failure should have escaped.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced final session link failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $fresh = $session->fresh();
        $this->assertSame(UploadSessionStatus::UPLOADING, $fresh->status);
        $this->assertNull($fresh->media_id);
        $this->assertNull($fresh->finalized_at);
        $this->assertSame(0, Media::query()->count());
        $this->assertSame(0, (int) $workspace->fresh()->storage_used_bytes);
        Storage::disk('local')->assertExists($chunkPath);
        $this->assertSame([$chunkPath], Storage::disk('local')->allFiles());
    }

    public function test_incomplete_session_cannot_be_finalized(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(ResumableUploadManager::class);

        $session = $service->startSession(new UploadSessionOptions(
            identifier: 'unfinished-upload',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::GENERAL,
                directory: 'workspace',
                module: 'file-centre',
                strategy: UploadStrategy::CHUNKED,
                temporary: false,
                originalName: 'draft.txt',
            ),
            totalChunks: 2,
            totalSize: 12,
            mimeType: 'text/plain',
        ));

        $service->appendChunk(
            $session,
            UploadedFile::fake()->createWithContent('chunk-1.part', 'partial'),
            1
        );

        $this->expectException(IncompleteUploadSessionException::class);

        $service->finalizeSession(UploadSession::query()->findOrFail($session->id));
    }
}
