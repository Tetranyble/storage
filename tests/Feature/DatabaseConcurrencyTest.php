<?php

namespace Tetranyble\Storage\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\ConnectedDriveService;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;
use Tetranyble\Storage\Modules\DirectUpload\Application\Contracts\DirectUploadManager;
use Tetranyble\Storage\Modules\DirectUpload\Application\DTO\DirectUploadRequest;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Contracts\DirectUploadGateway;
use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadObject;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadStatus;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Exceptions\DirectUploadConflictException;
use Tetranyble\Storage\Modules\DirectUpload\Infrastructure\Persistence\Eloquent\Models\DirectUploadSession;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Sharing\Domain\Exceptions\ShareDownloadLimitReachedException;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Application\MediaShareService;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Persistence\Eloquent\Models\MediaShare;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\FileSize;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\MimeType;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\Sha256Checksum;
use Tetranyble\Storage\Tests\Fixtures\DirectUploads\FakeDirectUploadGateway;
use Tetranyble\Storage\Modules\Quota\Domain\Exceptions\StorageQuotaExceededException;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Upload\Domain\Enums\UploadSessionStatus;
use Tetranyble\Storage\Modules\Upload\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageService;
use Tetranyble\Storage\Tests\PackageTestCase;

class DatabaseConcurrencyTest extends PackageTestCase
{
    public function test_atomic_quota_reservation_holds_across_database_processes(): void
    {
        $this->requireRealDatabaseAndPcntl();

        $workspace = Workspace::query()->create([
            'name' => 'Concurrent quota',
            'storage_quota_bytes' => 100,
            'storage_used_bytes' => 0,
        ]);

        $results = $this->runConcurrently(2, function (int $worker) use ($workspace): string {
            $this->reconnectDatabase();
            $fresh = Workspace::query()->findOrFail($workspace->id);

            try {
                $this->app->make(StorageService::class)->increaseUsage($fresh, 80);

                return 'ok';
            } catch (StorageQuotaExceededException) {
                return 'quota';
            } catch (\Throwable $e) {
                return 'error:'.get_class($e).':'.$e->getMessage();
            }
        });

        $this->reconnectDatabase();
        $workspace->refresh();

        sort($results);
        $this->assertSame(['ok', 'quota'], $results, implode(PHP_EOL, $results));
        $this->assertSame(80, (int) $workspace->storage_used_bytes);
    }

    public function test_single_active_upload_identifier_is_enforced_across_database_processes(): void
    {
        $this->requireRealDatabaseAndPcntl();

        $workspace = Workspace::query()->create(['name' => 'Concurrent uploads']);
        $activeHash = hash('sha256', $workspace->id.'|same-browser-upload');

        $results = $this->runConcurrently(2, function (int $worker) use ($workspace, $activeHash): string {
            $this->reconnectDatabase();

            try {
                UploadSession::query()->create([
                    'workspace_id' => $workspace->id,
                    'identifier' => 'same-browser-upload',
                    'active_identifier_hash' => $activeHash,
                    'fingerprint' => hash('sha256', 'worker-'.$worker),
                    'original_name' => 'payload.bin',
                    'mime_type' => 'application/octet-stream',
                    'disk' => Disk::PRIVATE,
                    'status' => UploadSessionStatus::PENDING,
                    'total_chunks' => 2,
                    'total_size' => 20,
                    'chunk_size' => 10,
                    'upload_options' => [],
                    'session_expires_at' => now()->addHour(),
                ]);

                return 'ok';
            } catch (QueryException) {
                return 'duplicate';
            } catch (\Throwable $e) {
                return 'error:'.get_class($e).':'.$e->getMessage();
            }
        });

        $this->reconnectDatabase();

        sort($results);
        $this->assertSame(['duplicate', 'ok'], $results, implode(PHP_EOL, $results));
        $this->assertSame(1, UploadSession::query()->where('active_identifier_hash', $activeHash)->count());
    }

    public function test_final_share_download_slot_is_atomic_across_database_processes(): void
    {
        $this->requireRealDatabaseAndPcntl();

        $workspace = Workspace::query()->create(['name' => 'Concurrent share']);
        $media = Media::query()->create([
            'workspace_id' => $workspace->id,
            'disk' => Disk::PRIVATE,
            'path' => 'concurrency/share.bin',
            'use' => MediaPurpose::GENERAL,
        ]);
        $share = MediaShare::query()->create([
            'workspace_id' => $workspace->id,
            'shareable_type' => Media::class,
            'shareable_id' => $media->id,
            'token' => Str::random(32),
            'access_level' => 'download',
            'max_downloads' => 1,
            'downloads_count' => 0,
        ]);

        $results = $this->runConcurrently(2, function () use ($share): string {
            $this->reconnectDatabase();

            try {
                $candidate = MediaShare::query()->findOrFail($share->id);
                $this->app->make(MediaShareService::class)->consumeDownloadAccess($candidate);

                return 'ok';
            } catch (ShareDownloadLimitReachedException) {
                return 'limit';
            } catch (\Throwable $e) {
                return 'error:'.get_class($e).':'.$e->getMessage();
            }
        });

        $this->reconnectDatabase();
        sort($results);
        $this->assertSame(['limit', 'ok'], $results, implode(PHP_EOL, $results));
        $this->assertSame(1, (int) $share->fresh()->downloads_count);
    }

    public function test_duplicate_direct_finalization_never_creates_two_media_rows_or_double_charges_quota(): void
    {
        $this->requireRealDatabaseAndPcntl();
        config()->set('tetranyble-storage.direct_uploads.enabled', true);
        config()->set('tetranyble-storage.direct_uploads.require_sha256', true);

        $gateway = new FakeDirectUploadGateway();
        $this->app->instance(DirectUploadGateway::class, $gateway);
        $workspace = Workspace::query()->create([
            'name' => 'Concurrent direct finalize',
            'storage_quota_bytes' => 100,
            'storage_used_bytes' => 0,
        ]);
        $sha = hash('sha256', 'hello');
        $manager = $this->app->make(DirectUploadManager::class);
        $session = $manager->start(new DirectUploadRequest(
            upload: new MediaUploadOptions(
                workspaceId: $workspace->id,
                disk: Disk::S3_PRIVATE,
                directory: 'concurrency',
                module: 'reliability',
                originalName: 'hello.bin',
            ),
            expectedSize: 5,
            mimeType: 'application/octet-stream',
            sha256: $sha,
        ))->session;
        $gateway->object = new DirectUploadObject(
            new FileSize(5),
            new MimeType('application/octet-stream'),
            new Sha256Checksum($sha),
        );

        $results = $this->runConcurrently(2, function () use ($session): string {
            $this->reconnectDatabase();

            try {
                $candidate = DirectUploadSession::query()->findOrFail($session->id);
                $this->app->make(DirectUploadManager::class)->finalize($candidate);

                return 'ok';
            } catch (DirectUploadConflictException $e) {
                return 'conflict:'.$e->reason;
            } catch (\Throwable $e) {
                return 'error:'.get_class($e).':'.$e->getMessage();
            }
        });

        $this->reconnectDatabase();
        foreach ($results as $result) {
            $this->assertContains($result, ['ok', 'conflict:finalizing'], implode(PHP_EOL, $results));
        }
        $this->assertContains('ok', $results);
        $this->assertSame(1, Media::query()->where('direct_upload_session_uuid', $session->uuid)->count());
        $this->assertSame(DirectUploadStatus::FINALIZED, $session->fresh()->status);
        $this->assertSame(5, (int) $workspace->fresh()->storage_used_bytes);
    }

    public function test_concurrent_first_drive_connections_both_succeed_with_exactly_one_default(): void
    {
        $this->requireRealDatabaseAndPcntl();

        $workspace = Workspace::query()->create(['name' => 'Concurrent drives']);
        $results = $this->runConcurrently(2, function (int $worker) use ($workspace): string {
            $this->reconnectDatabase();

            try {
                $fresh = Workspace::query()->findOrFail($workspace->id);
                $this->app->make(ConnectedDriveService::class)->connectLocal(
                    $fresh,
                    'local',
                    'Local '.$worker,
                );

                return 'ok';
            } catch (\Throwable $e) {
                return 'error:'.get_class($e).':'.$e->getMessage();
            }
        });

        $this->reconnectDatabase();
        sort($results);
        $this->assertSame(['ok', 'ok'], $results, implode(PHP_EOL, $results));
        $this->assertSame(2, ConnectedDrive::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, ConnectedDrive::query()->where('workspace_id', $workspace->id)->where('is_default', true)->count());
    }

    /**
     * @param  callable(int): string  $worker
     * @return list<string>
     */
    private function runConcurrently(int $workers, callable $worker): array
    {
        $directory = sys_get_temp_dir().'/tetranyble-storage-concurrency-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $startFile = $directory.'/start';
        $children = [];

        try {
            for ($i = 0; $i < $workers; $i++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('Unable to fork database concurrency worker.');
                }

                if ($pid === 0) {
                    while (! is_file($startFile)) {
                        usleep(1_000);
                    }

                    $result = $worker($i);
                    file_put_contents($directory.'/result-'.$i, $result, LOCK_EX);
                    exit(0);
                }

                $children[] = $pid;
            }

            touch($startFile);

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status), 'Concurrency worker did not exit normally.');
                $this->assertSame(0, pcntl_wexitstatus($status), 'Concurrency worker exited unsuccessfully.');
            }

            $results = [];
            for ($i = 0; $i < $workers; $i++) {
                $path = $directory.'/result-'.$i;
                $this->assertFileExists($path);
                $results[] = trim((string) file_get_contents($path));
            }

            return $results;
        } finally {
            foreach (glob($directory.'/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
    }

    private function requireRealDatabaseAndPcntl(): void
    {
        $connection = (string) (getenv('STORAGE_TEST_DB_CONNECTION') ?: 'sqlite');

        if ($connection === 'sqlite') {
            $this->markTestSkipped('This test validates cross-process PostgreSQL/MySQL concurrency semantics.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for cross-process database concurrency tests.');
        }
    }

    private function reconnectDatabase(): void
    {
        $connection = (string) config('database.default');
        DB::disconnect($connection);
        DB::purge($connection);
        DB::reconnect($connection);
    }
}
