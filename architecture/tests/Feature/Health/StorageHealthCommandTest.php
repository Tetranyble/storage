<?php

namespace Tetranyble\Storage\Tests\Feature\Health;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tetranyble\Storage\Modules\Processing\Domain\Enums\MediaProcessingStatus;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Storage\Infrastructure\Persistence\Eloquent\Models\StorageOrphan;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Modules\Upload\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use Tetranyble\Storage\Modules\DirectUpload\Infrastructure\Persistence\Eloquent\Models\DirectUploadSession;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;
use Tetranyble\Storage\Modules\Upload\Domain\Enums\UploadSessionStatus;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadStatus;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\ConnectedDriveStatus;
use Tetranyble\Storage\Tests\PackageTestCase;

class StorageHealthCommandTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('tetranyble-storage.observability.health.disks', ['local']);
    }

    public function test_health_command_reports_healthy_baseline(): void
    {
        $workspace = Workspace::create(['name' => 'Healthy']);

        $this->artisan('storage:health', ['--workspace' => $workspace->id])
            ->expectsOutputToContain('Overall: OK')
            ->assertSuccessful();
    }

    public function test_quota_drift_is_warning_and_health_check_is_read_only(): void
    {
        $workspace = Workspace::create([
            'name' => 'Drifted',
            'storage_used_bytes' => 1234,
        ]);

        $this->artisan('storage:health', ['--workspace' => $workspace->id])
            ->expectsOutputToContain('WARNING')
            ->assertSuccessful();

        $this->artisan('storage:health', ['--workspace' => $workspace->id, '--strict' => true])
            ->assertFailed();

        $this->assertSame(1234, (int) $workspace->fresh()->storage_used_bytes);
    }

    public function test_stale_processing_is_critical(): void
    {
        $workspace = Workspace::create(['name' => 'Processing']);
        Media::create([
            'workspace_id' => $workspace->id,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'disk' => Disk::PRIVATE,
            'path' => 'safe/opaque.bin',
            'size' => 10,
            'processing_status' => MediaProcessingStatus::PROCESSING,
            'processing_started_at' => now()->subHour(),
        ]);

        $this->artisan('storage:health', ['--workspace' => $workspace->id])
            ->expectsOutputToContain('CRITICAL')
            ->assertFailed();
    }

    public function test_json_output_never_exposes_orphan_object_paths(): void
    {
        $workspace = Workspace::create(['name' => 'Orphans']);
        StorageOrphan::create([
            'workspace_id' => $workspace->id,
            'disk' => Disk::PRIVATE->value,
            'path' => 'private/customer/secret-document.pdf',
            'object_key_hash' => hash('sha256', 'secret'),
            'reason' => 'cleanup',
        ]);

        $exit = Artisan::call('storage:health', [
            '--workspace' => $workspace->id,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"orphans"', $output);
        $this->assertStringNotContainsString('secret-document.pdf', $output);
        $this->assertStringNotContainsString('private/customer', $output);
        $this->assertJson($output);
    }

    public function test_stuck_resumable_and_direct_uploads_are_reported_as_aggregate_counts(): void
    {
        $workspace = Workspace::create(['name' => 'Uploads']);
        $user = \Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\User::create([
            'workspace_id' => $workspace->id,
            'name' => 'Uploader',
        ]);

        $resumable = UploadSession::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'identifier' => 'health-resumable',
            'active_identifier_hash' => hash('sha256', 'health-resumable'),
            'fingerprint' => hash('sha256', 'fingerprint'),
            'status' => UploadSessionStatus::UPLOADING,
            'total_chunks' => 2,
            'total_size' => 20,
            'received_chunks' => 1,
            'received_bytes' => 10,
            'upload_options' => [],
            'session_expires_at' => now()->addHour(),
        ]);
        $resumable->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        $direct = DirectUploadSession::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'provider' => 's3',
            'status' => DirectUploadStatus::UPLOADING,
            'disk' => Disk::PRIVATE,
            'object_key' => 'internal/never-output-this-key.bin',
            'object_key_hash' => hash('sha256', 'direct-health'),
            'original_name' => 'file.bin',
            'expected_size' => 20,
            'reserved_bytes' => 20,
            'upload_options' => [],
            'session_expires_at' => now()->addHour(),
            'cleanup_pending' => true,
        ]);
        $direct->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        Artisan::call('storage:health', ['--workspace' => $workspace->id, '--json' => true]);
        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $checks = collect($payload['checks'])->keyBy('name');

        $this->assertSame(1, $checks['resumable_uploads']['details']['stuck_count']);
        $this->assertSame(1, $checks['direct_uploads']['details']['stuck_count']);
        $this->assertSame(1, $checks['direct_uploads']['details']['cleanup_pending_count']);
        $this->assertStringNotContainsString('never-output-this-key', $output);
    }

    public function test_connected_drive_errors_are_aggregated_without_provider_error_bodies(): void
    {
        $workspace = Workspace::create(['name' => 'Drive health']);
        ConnectedDrive::create([
            'workspace_id' => $workspace->id,
            'provider' => CloudProvider::GOOGLE_DRIVE,
            'name' => 'Drive',
            'status' => ConnectedDriveStatus::ERROR,
            'last_error' => 'OAuth token abc123 failed for /secret/customer/path',
        ]);

        Artisan::call('storage:health', ['--workspace' => $workspace->id, '--json' => true]);
        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $checks = collect($payload['checks'])->keyBy('name');

        $this->assertSame('warning', $checks['connected_drives']['status']);
        $this->assertSame(1, $checks['connected_drives']['details']['error_count']);
        $this->assertStringNotContainsString('abc123', $output);
        $this->assertStringNotContainsString('/secret/customer/path', $output);
    }

    public function test_missing_configured_storage_disk_is_critical(): void
    {
        $workspace = Workspace::create(['name' => 'Disk health']);
        config()->set('tetranyble-storage.observability.health.disks', ['missing-health-disk']);

        $this->artisan('storage:health', ['--workspace' => $workspace->id])
            ->expectsOutputToContain('CRITICAL')
            ->assertFailed();
    }

    public function test_unknown_workspace_fails_without_running_diagnostics(): void
    {
        $this->artisan('storage:health', ['--workspace' => 999999])
            ->expectsOutput('Workspace not found.')
            ->assertFailed();
    }
}
