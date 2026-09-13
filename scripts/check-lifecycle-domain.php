<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$files = [
    'src/Modules/Shared/Domain/Exceptions/StorageException.php',
    'src/Modules/Storage/Domain/Exceptions/StorageException.php',
    'src/Modules/Access/Domain/Exceptions/AccessDeniedException.php',
    'src/Modules/Access/Domain/Exceptions/AuthenticationRequiredException.php',
    'src/Modules/Shared/Domain/Exceptions/ResourceNotFoundException.php',
    'src/Modules/DirectUpload/Domain/Exceptions/DirectUploadConflictException.php',
    'src/Modules/DirectUpload/Domain/Enums/DirectUploadStatus.php',
    'src/Modules/DirectUpload/Domain/Aggregates/DirectUploadLifecycle.php',
    'src/Modules/Upload/Domain/Exceptions/UploadSessionConflictException.php',
    'src/Modules/Upload/Domain/Enums/UploadSessionStatus.php',
    'src/Modules/Upload/Domain/Aggregates/ResumableUploadLifecycle.php',
    'src/Modules/Sharing/Domain/Exceptions/ShareExpiredException.php',
    'src/Modules/Sharing/Domain/Exceptions/ShareDownloadLimitReachedException.php',
    'src/Modules/Sharing/Domain/Exceptions/ShareDownloadNotAllowedException.php',
    'src/Modules/Trust/Domain/Exceptions/MediaQuarantinedException.php',
    'src/Modules/Trust/Domain/Exceptions/UnsafeMediaException.php',
    'src/Modules/Sharing/Domain/Enums/ShareAccessLevel.php',
    'src/Modules/Sharing/Domain/Policy/ShareAccessPolicy.php',
    'src/Modules/Versioning/Domain/Aggregates/VersionGroup.php',
];

foreach ($files as $file) {
    require_once $root.'/'.$file;
}

use Tetranyble\Storage\Modules\Access\Domain\Exceptions\AccessDeniedException;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\StorageException as SharedStorageException;
use Tetranyble\Storage\Modules\Trust\Domain\Exceptions\MediaQuarantinedException;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Aggregates\DirectUploadLifecycle;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadStatus;
use Tetranyble\Storage\Modules\Sharing\Domain\Enums\ShareAccessLevel;
use Tetranyble\Storage\Modules\Sharing\Domain\Exceptions\ShareDownloadLimitReachedException;
use Tetranyble\Storage\Modules\Sharing\Domain\Policy\ShareAccessPolicy;
use Tetranyble\Storage\Modules\Upload\Domain\Aggregates\ResumableUploadLifecycle;
use Tetranyble\Storage\Modules\Upload\Domain\Enums\UploadSessionStatus;
use Tetranyble\Storage\Modules\Versioning\Domain\Aggregates\VersionGroup;

$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "Lifecycle domain check failed: {$message}\n");
        exit(1);
    }
};

$check(is_subclass_of(AccessDeniedException::class, SharedStorageException::class), 'access exception hierarchy');
$check(is_subclass_of(MediaQuarantinedException::class, SharedStorageException::class), 'trust exception hierarchy');

$direct = DirectUploadLifecycle::reconstitute(DirectUploadStatus::PENDING, 2048);
$direct->beginUploading();
$direct->claimFinalization();
$direct->completeFinalization();
$check($direct->status() === DirectUploadStatus::FINALIZED, 'direct upload happy path');
$check($direct->reservedBytes() === 0, 'finalization clears session reservation');

$cancel = DirectUploadLifecycle::reconstitute(DirectUploadStatus::UPLOADING, 1024);
$check($cancel->cancel() === 1024, 'cancellation releases quota reservation');
$check($cancel->status() === DirectUploadStatus::CANCELLED, 'cancellation transition');

$resumable = ResumableUploadLifecycle::reconstitute(UploadSessionStatus::PENDING, 10);
$resumable->synchronizeProgress(1);
$resumable->claimAssembly();
$resumable->completeAssembly();
$check($resumable->status() === UploadSessionStatus::FINALIZED, 'resumable happy path');

$share = new ShareAccessPolicy(ShareAccessLevel::DOWNLOAD, null, 1, 1, false);
try {
    $share->assertAccessible(new DateTimeImmutable());
    $check(false, 'share download limit must reject exhausted shares');
} catch (ShareDownloadLimitReachedException) {
    // expected
}

$versions = new VersionGroup(observedMaxVersion: 8, nextVersionNumber: 5, currentMediaId: 1);
$check($versions->reserveNextVersion() === 9, 'version allocator must advance past observed max');

fwrite(STDOUT, "Lifecycle domain check passed (direct upload, resumable upload, sharing, versioning).\n");
