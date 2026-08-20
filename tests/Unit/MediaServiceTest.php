<?php

namespace Tetranyble\Storage\Tests\Unit;

use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\FileSystem\Enums\UploadStrategy;
use Tetranyble\Storage\Domain\FileSystem\Exceptions\RemoteDownloadException;
use Tetranyble\Storage\Domain\FileSystem\Exceptions\StorageQuotaExceededException;
use Tetranyble\Storage\Domain\FileSystem\MediaService;
use Tetranyble\Storage\Enums\MediaRevisionEventType;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Tests\Fixtures\Models\DummyMediableModel;
use Tetranyble\Storage\Tests\Fixtures\Models\Loan;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class MediaServiceTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_upload_standalone_creates_media_and_stores_file(): void
    {
        $service = $this->app->make(MediaService::class);
        $file = UploadedFile::fake()->image('avatar.jpg', 100, 100);
        $workspace = Workspace::create(['name' => 'Workspace']);

        $media = $service->uploadStandalone(
            $file,
            description: 'Test avatar',
            attribution: 'Unit test',
            directory: 'avatars',
            purpose: MediaPurpose::PROFILE,
            disk: Disk::PUBLIC,
            workspaceId: $workspace->id,
        );

        $this->assertInstanceOf(Media::class, $media);
        $this->assertSame(MediaPurpose::PROFILE, $media->use);
        $this->assertSame(Disk::PUBLIC, $media->disk);
        $this->assertTrue($media->current);
        $this->assertSame($workspace->id, $media->workspace_id);
        $this->assertSame(100, $media->width);
        $this->assertSame(100, $media->height);

        Storage::disk('public')->assertExists($media->path);
    }

    public function test_attach_external_for_youtube_sets_youtube_disk_and_preserves_url(): void
    {
        $service = $this->app->make(MediaService::class);
        $model = DummyMediableModel::create();
        $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

        $media = $service->attachExternalFor($model, $url, MediaPurpose::VIDEO);

        $this->assertSame($url, $media->path);
        $this->assertSame(Disk::YOUTUBE, $media->disk);
        $this->assertTrue($media->current);
        $this->assertSame(MediaPurpose::VIDEO, $media->use);
        $this->assertSame($url, $media->url);
    }

    public function test_attach_external_for_generic_url_defaults_to_public_disk(): void
    {
        $service = $this->app->make(MediaService::class);
        $model = DummyMediableModel::create();
        $url = 'https://cdn.example.com/images/banner.png';

        $media = $service->attachExternalFor($model, $url, MediaPurpose::BANNER);

        $this->assertSame($url, $media->path);
        $this->assertSame(Disk::PUBLIC, $media->disk);
        $this->assertSame(MediaPurpose::BANNER, $media->use);
        $this->assertSame($url, $media->url);
    }

    public function test_purge_media_deletes_files_and_force_deletes_rows(): void
    {
        $service = $this->app->make(MediaService::class);
        $model = DummyMediableModel::create();
        $file = UploadedFile::fake()->image('avatar.jpg', 100, 100);

        $media = $service->uploadFor(
            $model,
            $file,
            description: 'Avatar',
            attribution: 'Unit test',
            directory: 'avatars',
            purpose: MediaPurpose::PROFILE,
            disk: Disk::PUBLIC,
        );

        Storage::disk('public')->assertExists($media->path);
        $this->assertDatabaseHas('media', ['id' => $media->id, 'deleted_at' => null]);

        $service->purgeMedia($model);

        Storage::disk('public')->assertMissing($media->path);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_upload_standalone_from_url_respects_max_size_override(): void
    {
        $service = $this->app->make(MediaService::class);

        Http::fake([
            'https://example.com/big.bin' => Http::response(
                str_repeat('A', 1024),
                200,
                ['Content-Length' => 1024, 'Content-Type' => 'application/octet-stream']
            ),
        ]);

        $this->expectException(RemoteDownloadException::class);

        $service->uploadStandaloneFromUrl(
            'https://example.com/big.bin',
            MediaPurpose::GENERAL,
            Disk::PUBLIC,
            'Big file',
            'Test',
            directory: 'remote',
            workspaceId: null,
            maxSizeBytes: 512,
        );
    }

    public function test_upload_standalone_from_url_respects_allowed_mimes_override(): void
    {
        $service = $this->app->make(MediaService::class);

        Http::fake([
            'https://example.com/file.exe' => Http::response(
                'dummy',
                200,
                ['Content-Length' => 10, 'Content-Type' => 'application/x-msdownload']
            ),
        ]);

        $this->expectException(RemoteDownloadException::class);

        $service->uploadStandaloneFromUrl(
            'https://example.com/file.exe',
            MediaPurpose::GENERAL,
            Disk::PUBLIC,
            'Executable',
            'Test',
            directory: 'remote',
            workspaceId: null,
            maxSizeBytes: null,
            allowedMimes: ['image/png', 'application/pdf'],
        );
    }

    public function test_upload_for_model_uses_model_directory_and_folder(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $loan = Loan::create(['workspace_id' => $workspace->id]);
        $file = UploadedFile::fake()->create('payslip.pdf', 50, 'application/pdf');
        $service = $this->app->make(MediaService::class);

        $media = $service->uploadFor($loan, $file, 'Payslip', '', 'media', MediaPurpose::IDENTITY_DOCUMENT_FRONT);

        $this->assertNotNull($media->id);
        $this->assertSame($workspace->id, $media->workspace_id);
        $this->assertStringContainsString("/loans/{$loan->id}/identity-document-front", $media->path);
        $this->assertNotNull($media->folder_id);
        $this->assertFalse($media->is_temporary);
    }

    public function test_upload_standalone_marks_temporary_and_sets_expiry(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $file = UploadedFile::fake()->image('avatar.png', 300, 300);
        $service = $this->app->make(MediaService::class);

        $media = $service->uploadStandalone($file, '', '', 'uploads', MediaPurpose::PROFILE, workspaceId: $workspace->id);

        $this->assertTrue($media->is_temporary);
        $this->assertNotNull($media->temporary_expires_at);
        $this->assertSame($workspace->id, $media->workspace_id);
        $this->assertNotNull($media->folder_id);
    }

    public function test_upload_uploaded_file_persists_metadata_through_unified_service(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(MediaService::class);
        $file = UploadedFile::fake()->create('ledger.csv', 25, 'text/csv');

        $media = $service->uploadUploadedFile($file, MediaUploadOptions::forStandalone(
            workspaceId: $workspace->id,
            userId: 123,
            purpose: MediaPurpose::IMPORT_SOURCE,
            directory: 'imports',
            module: 'payslip',
            temporary: false,
            label: 'Ledger upload',
            attribution: 'Unit test',
            customProperties: ['source' => 'test'],
        ));

        $this->assertSame('payslip', $media->module);
        $this->assertSame(UploadStrategy::SINGLE, $media->upload_strategy);
        $this->assertSame('Ledger upload', $media->description);
        $this->assertSame(['source' => 'test'], $media->custom_properties);
        $this->assertSame($workspace->id, $media->workspace_id);
        $this->assertNotNull($media->sha256);
    }

    public function test_finalize_chunked_upload_marks_strategy_as_chunked(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(MediaService::class);
        $file = UploadedFile::fake()->create('statement.pdf', 25, 'application/pdf');

        $media = $service->finalizeChunkedUpload($file, MediaUploadOptions::forStandalone(
            workspaceId: $workspace->id,
            purpose: MediaPurpose::BANK_STATEMENT,
            directory: 'statements',
            module: 'statement',
            temporary: false,
        ));

        $this->assertSame(UploadStrategy::CHUNKED, $media->upload_strategy);
        $this->assertSame(Disk::PRIVATE, $media->disk);
        $this->assertStringContainsString('statement', $media->path);
    }

    public function test_attach_existing_media_to_model_moves_file_and_clears_temporary(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $loan = Loan::create(['workspace_id' => $workspace->id]);
        $file = UploadedFile::fake()->create('bank-statement.pdf', 100, 'application/pdf');
        $service = $this->app->make(MediaService::class);

        $media = $service->uploadStandalone($file, '', '', workspaceId: $workspace->id);
        $oldPath = $media->path;

        $attached = $service->attachExistingMediaToModel($media, $loan, MediaPurpose::BANK_STATEMENT);

        $this->assertSame($media->id, $attached->id);
        $this->assertSame($loan->id, $attached->mediable_id);
        $this->assertSame(Loan::class, $attached->mediable_type);
        $this->assertFalse($attached->is_temporary);
        $this->assertNull($attached->temporary_expires_at);
        $this->assertNotSame($oldPath, $attached->path);
        $this->assertStringContainsString("loans/{$loan->id}/bank-statement", $attached->path);
    }

    public function test_replace_existing_creates_media_revision_chain(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $loan = Loan::create(['workspace_id' => $workspace->id]);
        $service = $this->app->make(MediaService::class);

        $first = $service->uploadFor(
            $loan,
            UploadedFile::fake()->create('statement-v1.pdf', 40, 'application/pdf'),
            directory: 'media',
            purpose: MediaPurpose::BANK_STATEMENT,
            disk: Disk::PRIVATE,
        );

        $second = $service->uploadFor(
            $loan,
            UploadedFile::fake()->create('statement-v2.pdf', 45, 'application/pdf'),
            directory: 'media',
            purpose: MediaPurpose::BANK_STATEMENT,
            disk: Disk::PRIVATE,
            replaceExisting: true,
        );

        $this->assertFalse($first->fresh()->current);
        $this->assertTrue($second->current);
        $this->assertSame($first->id, $second->previous_version_id);
        $this->assertSame($first->fresh()->version_group_uuid, $second->version_group_uuid);
        $this->assertSame(1, $first->fresh()->version_number);
        $this->assertSame(2, $second->version_number);
        $this->assertDatabaseHas('activities', [
            'subject_id' => $second->id,
            'subject_type' => $second->getMorphClass(),
            'type' => 'storage.media.'.MediaRevisionEventType::REVISION_UPLOADED->value,
            'user_id' => null,
        ]);
    }

    public function test_upload_can_opt_out_of_becoming_current(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $loan = Loan::create(['workspace_id' => $workspace->id]);
        $service = $this->app->make(MediaService::class);

        $current = $service->uploadFor(
            $loan,
            UploadedFile::fake()->image('avatar-current.png'),
            purpose: MediaPurpose::PROFILE,
            disk: Disk::PUBLIC,
        );
        $candidate = $service->uploadFor(
            $loan,
            UploadedFile::fake()->image('avatar-candidate.png'),
            purpose: MediaPurpose::PROFILE,
            disk: Disk::PUBLIC,
            makeCurrent: false,
        );

        $this->assertTrue($current->fresh()->current);
        $this->assertFalse($candidate->current);
        $this->assertSame($current->id, $loan->currentMedia(MediaPurpose::PROFILE)?->id);
    }

    public function test_new_media_becomes_the_only_current_item_without_creating_a_revision(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $loan = Loan::create(['workspace_id' => $workspace->id]);
        $service = $this->app->make(MediaService::class);
        $first = $service->uploadFor(
            $loan,
            UploadedFile::fake()->image('first.png'),
            purpose: MediaPurpose::PROFILE,
            disk: Disk::PUBLIC,
        );
        $second = $service->uploadFor(
            $loan,
            UploadedFile::fake()->image('second.png'),
            purpose: MediaPurpose::PROFILE,
            disk: Disk::PUBLIC,
        );

        $this->assertFalse($first->fresh()->current);
        $this->assertTrue($second->current);
        $this->assertNull($second->previous_version_id);
        $this->assertSame(1, $loan->media()->where('use', MediaPurpose::PROFILE)->where('current', true)->count());
    }

    public function test_selecting_current_media_clears_other_items_for_the_same_purpose(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $loan = Loan::create(['workspace_id' => $workspace->id]);
        $service = $this->app->make(MediaService::class);
        $first = $service->uploadFor(
            $loan,
            UploadedFile::fake()->image('first.png'),
            purpose: MediaPurpose::PROFILE,
            disk: Disk::PUBLIC,
        );
        $second = $service->uploadFor(
            $loan,
            UploadedFile::fake()->image('second.png'),
            purpose: MediaPurpose::PROFILE,
            disk: Disk::PUBLIC,
            makeCurrent: false,
        );

        $service->setCurrentMedia($second);

        $this->assertFalse($first->fresh()->current);
        $this->assertTrue($second->fresh()->current);
        $this->assertSame(1, $loan->media()->where('use', MediaPurpose::PROFILE)->where('current', true)->count());
    }

    public function test_restore_revision_creates_new_current_version(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $loan = Loan::create(['workspace_id' => $workspace->id]);
        $service = $this->app->make(MediaService::class);

        $first = $service->uploadFor(
            $loan,
            UploadedFile::fake()->create('statement-v1.pdf', 40, 'application/pdf'),
            directory: 'media',
            purpose: MediaPurpose::BANK_STATEMENT,
            disk: Disk::PRIVATE,
        );

        $second = $service->uploadFor(
            $loan,
            UploadedFile::fake()->create('statement-v2.pdf', 45, 'application/pdf'),
            directory: 'media',
            purpose: MediaPurpose::BANK_STATEMENT,
            disk: Disk::PRIVATE,
            replaceExisting: true,
        );

        $restored = $service->restoreRevision($first);

        $this->assertFalse($second->fresh()->current);
        $this->assertTrue($restored->current);
        $this->assertSame($first->id, $restored->previous_version_id);
        $this->assertSame($second->version_group_uuid, $restored->version_group_uuid);
        $this->assertSame(3, $restored->version_number);
        Storage::disk('local')->assertExists($restored->path);
        $this->assertDatabaseHas('activities', [
            'subject_id' => $restored->id,
            'subject_type' => $restored->getMorphClass(),
            'type' => 'storage.media.'.MediaRevisionEventType::REVISION_RESTORED->value,
        ]);
    }

    public function test_quota_is_enforced_for_workspace_uploads(): void
    {
        $workspace = Workspace::create([
            'name' => 'Workspace',
            'storage_quota_bytes' => 100,
            'storage_used_bytes' => 0,
        ]);
        $loan = Loan::create(['workspace_id' => $workspace->id]);
        $file = UploadedFile::fake()->create('large.pdf', 200, 'application/pdf');
        $service = $this->app->make(MediaService::class);

        $this->expectException(StorageQuotaExceededException::class);

        $service->uploadFor($loan, $file, '', '', 'media', MediaPurpose::GENERAL);
    }
}
