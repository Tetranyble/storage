<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Aggregates\DirectUploadLifecycle;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadStatus;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Exceptions\DirectUploadConflictException;
use Tetranyble\Storage\Modules\Sharing\Domain\Enums\ShareAccessLevel;
use Tetranyble\Storage\Modules\Sharing\Domain\Exceptions\ShareDownloadLimitReachedException;
use Tetranyble\Storage\Modules\Sharing\Domain\Exceptions\ShareDownloadNotAllowedException;
use Tetranyble\Storage\Modules\Sharing\Domain\Exceptions\ShareExpiredException;
use Tetranyble\Storage\Modules\Sharing\Domain\Policy\ShareAccessPolicy;
use Tetranyble\Storage\Modules\Upload\Domain\Aggregates\ResumableUploadLifecycle;
use Tetranyble\Storage\Modules\Upload\Domain\Enums\UploadSessionStatus;
use Tetranyble\Storage\Modules\Upload\Domain\Exceptions\UploadSessionConflictException;
use Tetranyble\Storage\Modules\Versioning\Domain\Aggregates\VersionGroup;

final class LifecycleStateMachinesTest extends TestCase
{
    public function test_direct_upload_happy_path_is_explicit(): void
    {
        $lifecycle = DirectUploadLifecycle::reconstitute(DirectUploadStatus::PENDING, 4096);
        $lifecycle->beginUploading();
        $lifecycle->claimFinalization();
        $lifecycle->completeFinalization();

        self::assertSame(DirectUploadStatus::FINALIZED, $lifecycle->status());
        self::assertSame(0, $lifecycle->reservedBytes());
    }

    public function test_direct_upload_cancel_releases_reserved_quota(): void
    {
        $lifecycle = DirectUploadLifecycle::reconstitute(DirectUploadStatus::UPLOADING, 8192);

        self::assertSame(8192, $lifecycle->cancel());
        self::assertSame(DirectUploadStatus::CANCELLED, $lifecycle->status());
        self::assertSame(0, $lifecycle->reservedBytes());
        self::assertSame(0, $lifecycle->cancel(), 'Terminal cancellation must be idempotent.');
    }

    public function test_direct_upload_cannot_cancel_during_finalization(): void
    {
        $lifecycle = DirectUploadLifecycle::reconstitute(DirectUploadStatus::FINALIZING, 1024);

        $this->expectException(DirectUploadConflictException::class);
        $this->expectExceptionMessage('finalization is already in progress');
        $lifecycle->cancel();
    }

    public function test_resumable_upload_transition_rules_are_framework_free(): void
    {
        $lifecycle = ResumableUploadLifecycle::reconstitute(UploadSessionStatus::PENDING, 42);
        $lifecycle->synchronizeProgress(1);
        self::assertSame(UploadSessionStatus::UPLOADING, $lifecycle->status());

        $lifecycle->claimAssembly();
        self::assertSame(UploadSessionStatus::ASSEMBLING, $lifecycle->status());

        $lifecycle->completeAssembly();
        self::assertSame(UploadSessionStatus::FINALIZED, $lifecycle->status());
    }

    public function test_resumable_finalized_session_rejects_new_chunks(): void
    {
        $lifecycle = ResumableUploadLifecycle::reconstitute(UploadSessionStatus::FINALIZED, 7);

        try {
            $lifecycle->assertReceivesChunks();
            self::fail('Expected finalized session to reject chunks.');
        } catch (UploadSessionConflictException $exception) {
            self::assertSame('session_finalized', $exception->reason);
            self::assertSame(['session_id' => 7], $exception->context);
        }
    }

    public function test_share_policy_enforces_expiry_download_limit_and_access_level(): void
    {
        $now = new DateTimeImmutable('2026-09-13T12:00:00+00:00');

        $expired = new ShareAccessPolicy(
            ShareAccessLevel::DOWNLOAD,
            $now->modify('-1 second'),
            null,
            0,
            false,
        );
        $this->expectException(ShareExpiredException::class);
        $expired->assertAccessible($now);
    }

    public function test_share_policy_rejects_exhausted_downloads(): void
    {
        $policy = new ShareAccessPolicy(ShareAccessLevel::DOWNLOAD, null, 2, 2, false);

        $this->expectException(ShareDownloadLimitReachedException::class);
        $policy->assertAccessible(new DateTimeImmutable);
    }

    public function test_share_policy_rejects_download_for_view_only_share(): void
    {
        $policy = new ShareAccessPolicy(ShareAccessLevel::VIEW, null, null, 0, false);

        $this->expectException(ShareDownloadNotAllowedException::class);
        $policy->assertDownloadAllowed(new DateTimeImmutable);
    }

    public function test_version_group_reservation_survives_stale_allocator(): void
    {
        $group = new VersionGroup(observedMaxVersion: 9, nextVersionNumber: 7, currentMediaId: 10);

        self::assertSame(10, $group->reserveNextVersion());
    }
}
