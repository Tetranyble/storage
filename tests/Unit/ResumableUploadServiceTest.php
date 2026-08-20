<?php

namespace Tetranyble\Storage\Tests\Unit;

use Tetranyble\Storage\Contracts\ResumableUploadManager;
use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Domain\FileSystem\DTO\UploadSessionOptions;
use Tetranyble\Storage\Domain\FileSystem\Enums\UploadSessionStatus;
use Tetranyble\Storage\Domain\FileSystem\Enums\UploadStrategy;
use Tetranyble\Storage\Domain\FileSystem\Exceptions\IncompleteUploadSessionException;
use Tetranyble\Storage\Domain\FileSystem\Exceptions\UploadSessionConflictException;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Models\UploadSession;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ResumableUploadServiceTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_resumable_upload_session_tracks_progress_and_finalizes_into_media(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(ResumableUploadManager::class);

        $session = $service->startSession(new UploadSessionOptions(
            identifier: 'statement-2026-06',
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::BANK_STATEMENT,
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
