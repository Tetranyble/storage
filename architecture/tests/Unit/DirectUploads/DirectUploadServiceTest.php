<?php

namespace Tetranyble\Storage\Tests\Unit\DirectUploads;

use Illuminate\Support\Facades\Event;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Contracts\DirectUploadGateway;
use Tetranyble\Storage\Modules\DirectUpload\Application\Contracts\DirectUploadManager;
use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadObject;
use Tetranyble\Storage\Modules\DirectUpload\Domain\ValueObject\ETag;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\FileSize;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\MimeType;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\Sha256Checksum;
use Tetranyble\Storage\Modules\DirectUpload\Application\DTO\DirectUploadRequest;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadMode;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadStatus;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Exceptions\DirectUploadConflictException;
use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\InvalidStorageOperationException;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Upload\Domain\Enums\UploadStrategy;
use Tetranyble\Storage\Modules\DirectUpload\Infrastructure\Persistence\Eloquent\Models\DirectUploadSession;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageService;
use Tetranyble\Storage\Tests\Fixtures\DirectUploads\FakeDirectUploadGateway;
use Tetranyble\Storage\Tests\PackageTestCase;

class DirectUploadServiceTest extends PackageTestCase
{
    private FakeDirectUploadGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('tetranyble-storage.direct_uploads.enabled', true);
        config()->set('tetranyble-storage.direct_uploads.require_sha256', true);
        config()->set('tetranyble-storage.direct_uploads.multipart_threshold', 6 * 1024 * 1024);
        config()->set('tetranyble-storage.direct_uploads.part_size', 5 * 1024 * 1024);

        $this->gateway = new FakeDirectUploadGateway();
        $this->app->instance(DirectUploadGateway::class, $this->gateway);
    }

    public function test_unsupported_direct_disk_returns_server_fallback_without_reserving_quota(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $manager = $this->app->make(DirectUploadManager::class);

        $result = $manager->start($this->request($workspace, 5, Disk::PRIVATE));

        $this->assertSame(DirectUploadMode::FALLBACK, $result->mode);
        $this->assertNull($result->session);
        $this->assertSame(0, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertSame(0, DirectUploadSession::query()->count());
    }

    public function test_direct_session_reserves_quota_and_single_upload_finalization_transfers_reservation_to_media(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $sha = hash('sha256', 'hello');
        $manager = $this->app->make(DirectUploadManager::class);

        $started = $manager->start($this->request($workspace, 5, Disk::S3_PRIVATE, $sha));
        $session = $started->session;

        $this->assertSame(DirectUploadMode::SINGLE, $started->mode);
        $this->assertSame(5, $started->plan?->expectedSize);
        $this->assertSame(5, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertSame(5, (int) $session->reserved_bytes);

        $this->gateway->object = new DirectUploadObject(new FileSize(5), new MimeType('text/plain'), new Sha256Checksum($sha), new ETag('etag'));
        $media = $manager->finalize($session);

        $this->assertInstanceOf(Media::class, $media);
        $this->assertSame(UploadStrategy::DIRECT, $media->upload_strategy);
        $this->assertSame($sha, $media->sha256);
        $this->assertSame($session->uuid, $media->direct_upload_session_uuid);
        $this->assertSame(5, (int) $workspace->fresh()->storage_used_bytes, 'Finalization must not double-charge reserved quota.');
        $this->assertDatabaseHas('direct_upload_sessions', [
            'id' => $session->id,
            'media_id' => $media->id,
            'status' => DirectUploadStatus::FINALIZED->value,
            'reserved_bytes' => 0,
        ]);
    }

    public function test_finalization_is_idempotent_by_direct_upload_session_uuid(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $sha = hash('sha256', 'hello');
        $manager = $this->app->make(DirectUploadManager::class);
        $session = $manager->start($this->request($workspace, 5, Disk::S3_PRIVATE, $sha))->session;
        $this->gateway->object = new DirectUploadObject(new FileSize(5), new MimeType('text/plain'), new Sha256Checksum($sha));

        $first = $manager->finalize($session);
        $second = $manager->finalize($session->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Media::query()->where('direct_upload_session_uuid', $session->uuid)->count());
        $this->assertSame(5, (int) $workspace->fresh()->storage_used_bytes);
    }

    public function test_checksum_mismatch_fails_session_releases_quota_and_prevents_media_registration(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $expected = hash('sha256', 'expected');
        $manager = $this->app->make(DirectUploadManager::class);
        $session = $manager->start($this->request($workspace, 8, Disk::S3_PRIVATE, $expected))->session;
        $this->gateway->object = new DirectUploadObject(new FileSize(8), new MimeType('application/octet-stream'), new Sha256Checksum(hash('sha256', 'different')));

        try {
            $manager->finalize($session);
            $this->fail('Checksum mismatch should fail direct-upload finalization.');
        } catch (DirectUploadConflictException $exception) {
            $this->assertSame('checksum_mismatch', $exception->reason);
        }

        $this->assertSame(0, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertSame(0, Media::query()->count());
        $this->assertSame(DirectUploadStatus::FAILED, $session->fresh()->status);
    }

    public function test_cancel_releases_reserved_quota_and_aborts_multipart_upload(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $manager = $this->app->make(DirectUploadManager::class);
        $session = $manager->start($this->request($workspace, 11 * 1024 * 1024, Disk::S3_PRIVATE))->session;

        $this->assertSame(DirectUploadMode::MULTIPART, $session->mode);
        $this->assertSame(11 * 1024 * 1024, (int) $workspace->fresh()->storage_used_bytes);

        $manager->cancel($session);

        $this->assertSame(0, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertSame(DirectUploadStatus::CANCELLED, $session->fresh()->status);
        $this->assertSame(1, $this->gateway->abortCalls);
    }

    public function test_provider_start_failure_releases_reserved_quota_and_leaves_a_durable_failed_session(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $this->gateway->failBegin = true;
        $manager = $this->app->make(DirectUploadManager::class);

        try {
            $manager->start($this->request($workspace, 5, Disk::S3_PRIVATE));
            $this->fail('Provider failure should abort session creation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('provider start failed', $exception->getMessage());
        }

        $this->assertSame(0, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertSame(1, DirectUploadSession::query()->count());
        $this->assertSame(DirectUploadStatus::FAILED, DirectUploadSession::query()->firstOrFail()->status);
        $this->assertSame(0, (int) DirectUploadSession::query()->firstOrFail()->reserved_bytes);
    }

    public function test_session_persistence_failure_rolls_back_quota_reservation_atomically(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $manager = $this->app->make(DirectUploadManager::class);
        $event = 'eloquent.creating: '.DirectUploadSession::class;
        Event::listen($event, static function (): never {
            throw new \RuntimeException('forced direct session persistence failure');
        });

        try {
            $manager->start($this->request($workspace, 5, Disk::S3_PRIVATE));
            $this->fail('The direct session persistence failure should escape.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced direct session persistence failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertSame(0, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertSame(0, DirectUploadSession::query()->count());
    }

    public function test_multipart_signing_enforces_part_range_and_incomplete_finalization_is_retryable(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $size = 11 * 1024 * 1024;
        $manager = $this->app->make(DirectUploadManager::class);
        $session = $manager->start($this->request($workspace, $size, Disk::S3_PRIVATE))->session;

        $parts = $manager->signParts($session, [1, 2, 3]);
        $this->assertCount(3, $parts);
        $this->assertSame(5 * 1024 * 1024, $parts[0]->expectedSize?->bytes);
        $this->assertSame(5 * 1024 * 1024, $parts[1]->expectedSize?->bytes);
        $this->assertSame(1 * 1024 * 1024, $parts[2]->expectedSize?->bytes);

        try {
            $manager->finalize($session, [['part_number' => 1, 'etag' => 'one']]);
            $this->fail('Incomplete multipart completion must be rejected without destroying the upload session.');
        } catch (DirectUploadConflictException $exception) {
            $this->assertSame('incomplete_parts', $exception->reason);
        }

        $this->assertSame(DirectUploadStatus::UPLOADING, $session->fresh()->status);
        $this->assertSame($size, (int) $session->fresh()->reserved_bytes);
        $this->assertSame($size, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertSame(0, Media::query()->count());
    }

    public function test_failed_multipart_abort_is_retained_for_cleanup_retry(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $manager = $this->app->make(DirectUploadManager::class);
        $session = $manager->start($this->request($workspace, 11 * 1024 * 1024, Disk::S3_PRIVATE))->session;
        $this->gateway->failAbort = true;

        $manager->cancel($session);

        $this->assertSame(0, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertSame(DirectUploadStatus::CANCELLED, $session->fresh()->status);
        $this->assertTrue((bool) $session->fresh()->cleanup_pending);
        $this->assertSame(1, $this->gateway->abortCalls);

        $this->gateway->failAbort = false;
        $result = $manager->cleanupExpired();

        $this->assertSame(1, $result['cleaned']);
        $this->assertSame(0, $result['failed']);
        $this->assertFalse((bool) $session->fresh()->cleanup_pending);
        $this->assertSame(2, $this->gateway->abortCalls);
    }

    public function test_expired_direct_upload_releases_quota_and_cleans_provider_state(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $size = 11 * 1024 * 1024;
        $manager = $this->app->make(DirectUploadManager::class);
        $session = $manager->start($this->request($workspace, $size, Disk::S3_PRIVATE))->session;
        $session->forceFill(['session_expires_at' => now()->subMinute()])->save();

        $result = $manager->cleanupExpired();

        $this->assertSame(1, $result['expired']);
        $this->assertSame(0, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertSame(DirectUploadStatus::EXPIRED, $session->fresh()->status);
        $this->assertFalse((bool) $session->fresh()->cleanup_pending);
        $this->assertGreaterThanOrEqual(1, $this->gateway->abortCalls);
    }

    public function test_size_mismatch_is_terminal_and_releases_reserved_quota(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $manager = $this->app->make(DirectUploadManager::class);
        $session = $manager->start($this->request($workspace, 8, Disk::S3_PRIVATE))->session;
        $this->gateway->object = new DirectUploadObject(new FileSize(7), new MimeType('application/octet-stream'), new Sha256Checksum((string) $session->expected_sha256));

        try {
            $manager->finalize($session);
            $this->fail('A provider-side object size mismatch must fail finalization.');
        } catch (DirectUploadConflictException $exception) {
            $this->assertSame('size_mismatch', $exception->reason);
        }

        $this->assertSame(DirectUploadStatus::FAILED, $session->fresh()->status);
        $this->assertSame(0, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertSame(0, Media::query()->count());
    }

    public function test_usage_reconciliation_does_not_double_count_media_committed_before_session_linkage(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $sha = hash('sha256', 'hello');
        $manager = $this->app->make(DirectUploadManager::class);
        $session = $manager->start($this->request($workspace, 5, Disk::S3_PRIVATE, $sha))->session;
        $this->gateway->object = new DirectUploadObject(new FileSize(5), new MimeType('text/plain'), new Sha256Checksum($sha));
        $media = $manager->finalize($session);

        // Simulate the narrow recovery window where Media committed but the
        // direct-upload session linkage/reservation release did not.
        $session->forceFill([
            'media_id' => null,
            'status' => DirectUploadStatus::UPLOADING,
            'reserved_bytes' => 5,
            'finalized_at' => null,
        ])->save();
        $workspace->newQuery()->whereKey($workspace->getKey())->update(['storage_used_bytes' => 999]);

        $this->app->make(StorageService::class)->recalculateUsage($workspace->fresh());

        $this->assertSame($session->uuid, $media->direct_upload_session_uuid);
        $this->assertSame(5, (int) $workspace->fresh()->storage_used_bytes);
    }

    public function test_multipart_session_can_start_without_eagerly_signing_any_parts(): void
    {
        config()->set('tetranyble-storage.direct_uploads.initial_signed_parts', 0);
        $workspace = Workspace::create(['name' => 'Workspace']);
        $manager = $this->app->make(DirectUploadManager::class);

        $started = $manager->start($this->request($workspace, 11 * 1024 * 1024, Disk::S3_PRIVATE));

        $this->assertSame(DirectUploadMode::MULTIPART, $started->mode);
        $this->assertSame([], $started->plan?->parts);
        $this->assertSame([], $this->gateway->signedParts);
    }

    public function test_usage_reconciliation_preserves_in_flight_direct_upload_reservations(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $manager = $this->app->make(DirectUploadManager::class);
        $session = $manager->start($this->request($workspace, 9, Disk::S3_PRIVATE))->session;

        $workspace->newQuery()->whereKey($workspace->getKey())->update(['storage_used_bytes' => 999]);
        $this->app->make(StorageService::class)->recalculateUsage($workspace->fresh());

        $this->assertSame(9, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertSame(9, (int) $session->fresh()->reserved_bytes);
    }

    public function test_sha256_is_required_by_default_and_size_ceiling_is_enforced_below_http(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $manager = $this->app->make(DirectUploadManager::class);

        try {
            $manager->start(new DirectUploadRequest(
                upload: new MediaUploadOptions(
                    workspaceId: $workspace->id,
                    disk: Disk::S3_PRIVATE,
                    originalName: 'missing.bin',
                ),
                expectedSize: 5,
                sha256: null,
            ));
            $this->fail('Missing checksum should be rejected.');
        } catch (InvalidStorageOperationException $exception) {
            $this->assertStringContainsString('SHA-256', $exception->getMessage());
        }

        config()->set('tetranyble-storage.uploads.max_size', 4);
        $this->expectException(InvalidStorageOperationException::class);
        $manager->start($this->request($workspace, 5, Disk::S3_PRIVATE));
    }

    private function request(
        Workspace $workspace,
        int $size,
        Disk $disk,
        ?string $sha = null,
    ): DirectUploadRequest {
        return new DirectUploadRequest(
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                disk: $disk,
                directory: 'direct-tests',
                module: 'direct-tests',
                originalName: 'upload.bin',
            ),
            expectedSize: $size,
            mimeType: 'application/octet-stream',
            sha256: $sha ?? hash('sha256', 'direct-test-'.$size),
        );
    }
}
