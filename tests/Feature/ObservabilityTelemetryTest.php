<?php

namespace Tetranyble\Storage\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Domain\Enums\AccessScope;
use Tetranyble\Storage\Modules\Access\Domain\Exceptions\AccessDeniedException;
use Tetranyble\Storage\Modules\Observability\Domain\Contracts\StorageTelemetry;
use Tetranyble\Storage\Modules\Observability\Domain\Enums\TelemetryLevel;
use Tetranyble\Storage\Modules\Quota\Domain\Exceptions\StorageQuotaExceededException;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Events\StorageTelemetryRecorded;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\User;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageService;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\OAuthService;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\ConnectedDriveStatus;
use Tetranyble\Storage\Modules\Health\Infrastructure\Application\StorageHealthService;
use Tetranyble\Storage\Modules\Storage\Infrastructure\Persistence\Eloquent\Models\StorageOrphan;
use Tetranyble\Storage\Modules\Observability\Infrastructure\NullStorageTelemetry;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageLifecycleService;
use Tetranyble\Storage\Tests\PackageTestCase;

class ObservabilityTelemetryTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('tetranyble-storage.observability.enabled', true);
        config()->set('tetranyble-storage.observability.log_records', false);
        config()->set('tetranyble-storage.observability.dispatch_events', true);
        Event::fake([StorageTelemetryRecorded::class]);
    }

    public function test_structured_telemetry_strips_sensitive_context(): void
    {
        app(StorageTelemetry::class)->event('test.sensitive', [
            'workspace_id' => 10,
            'path' => 'private/secret.pdf',
            'access_token' => 'token-value',
            'nested' => [
                'client_secret' => 'secret-value',
                'provider' => 'google_drive',
            ],
        ], TelemetryLevel::WARNING);

        Event::assertDispatched(StorageTelemetryRecorded::class, function (StorageTelemetryRecorded $event): bool {
            return $event->name === 'test.sensitive'
                && ($event->context['workspace_id'] ?? null) === 10
                && ! array_key_exists('path', $event->context)
                && ! array_key_exists('access_token', $event->context)
                && ($event->context['nested']['provider'] ?? null) === 'google_drive'
                && ! array_key_exists('client_secret', $event->context['nested'] ?? []);
        });
    }

    public function test_quota_rejection_emits_counter_and_warning_event(): void
    {
        $workspace = Workspace::create([
            'name' => 'Quota',
            'storage_quota_bytes' => 100,
            'storage_used_bytes' => 90,
        ]);

        try {
            app(StorageService::class)->increaseUsage($workspace, 20);
            $this->fail('Quota rejection expected.');
        } catch (StorageQuotaExceededException) {
            // expected
        }

        Event::assertDispatched(StorageTelemetryRecorded::class, fn (StorageTelemetryRecorded $event) =>
            $event->type === 'counter' && $event->name === 'quota.rejections');
        Event::assertDispatched(StorageTelemetryRecorded::class, fn (StorageTelemetryRecorded $event) =>
            $event->type === 'event' && $event->name === 'quota.rejected' && $event->level === 'warning');
    }

    public function test_access_denial_emits_safe_telemetry(): void
    {
        $workspace = Workspace::create(['name' => 'ACL']);
        $user = User::create(['workspace_id' => $workspace->id, 'name' => 'Viewer']);
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'disk' => Disk::PRIVATE,
            'path' => 'opaque/file.bin',
            'size' => 1,
            'access_scope' => AccessScope::RESTRICTED,
        ]);

        try {
            app(ResourceAccessControl::class)->authorizeView($workspace, $media, $user);
            $this->fail('Access denial expected.');
        } catch (AccessDeniedException) {
            // expected
        }

        Event::assertDispatched(StorageTelemetryRecorded::class, fn (StorageTelemetryRecorded $event) =>
            $event->name === 'access.denied'
            && ($event->context['workspace_id'] ?? null) === (int) $workspace->id
            && ($event->context['user_id'] ?? null) === (int) $user->id
            && ! array_key_exists('path', $event->context));
    }
    public function test_compensated_upload_failure_emits_upload_failure_telemetry(): void
    {
        Storage::fake('local');
        $workspace = Workspace::create(['name' => 'Lifecycle telemetry']);

        try {
            app(StorageLifecycleService::class)->storeAndCommit(
                workspace: $workspace,
                disk: Disk::PRIVATE,
                size: 4,
                store: function (): string {
                    Storage::disk('local')->put('telemetry/failure.bin', 'data');
                    return 'telemetry/failure.bin';
                },
                commit: fn () => throw new \RuntimeException('database failed'),
                rollbackReason: 'telemetry_test_rollback',
            );
            $this->fail('Lifecycle failure expected.');
        } catch (\RuntimeException) {
            // expected
        }

        Event::assertDispatched(StorageTelemetryRecorded::class, fn (StorageTelemetryRecorded $event) =>
            $event->type === 'counter'
            && $event->name === 'uploads.failures'
            && ($event->context['reason'] ?? null) === 'telemetry_test_rollback');
        Event::assertDispatched(StorageTelemetryRecorded::class, fn (StorageTelemetryRecorded $event) =>
            $event->type === 'event'
            && $event->name === 'upload.lifecycle_failed'
            && ! array_key_exists('path', $event->context));
        Storage::disk('local')->assertMissing('telemetry/failure.bin');
        $this->assertSame(0, (int) $workspace->fresh()->storage_used_bytes);
    }

    public function test_oauth_refresh_failure_emits_provider_failure_telemetry(): void
    {
        $workspace = Workspace::create(['name' => 'OAuth']);
        $drive = ConnectedDrive::create([
            'workspace_id' => $workspace->id,
            'provider' => CloudProvider::DROPBOX,
            'name' => 'Dropbox',
            'access_token' => 'old-access',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->subMinute(),
            'status' => ConnectedDriveStatus::CONNECTED,
        ]);

        Http::fake(['https://api.dropboxapi.com/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $oauth = new OAuthService([
            'dropbox' => [
                'client_id' => 'client',
                'client_secret' => 'secret',
                'redirect_uri' => 'https://app.test/callback',
            ],
        ], app(StorageTelemetry::class));

        try {
            $oauth->refreshAccessToken($drive);
            $this->fail('OAuth refresh failure expected.');
        } catch (\RuntimeException) {
            // expected
        }

        Event::assertDispatched(StorageTelemetryRecorded::class, fn (StorageTelemetryRecorded $event) =>
            $event->name === 'oauth.refresh_failed'
            && ($event->context['provider'] ?? null) === 'dropbox'
            && ! array_key_exists('refresh_token', $event->context));
    }

    public function test_health_check_emits_aggregate_backlog_gauges_without_paths(): void
    {
        config()->set('tetranyble-storage.observability.health.disks', ['local']);
        \Illuminate\Support\Facades\Storage::fake('local');
        $workspace = Workspace::create(['name' => 'Health metrics']);
        StorageOrphan::create([
            'workspace_id' => $workspace->id,
            'disk' => Disk::PRIVATE->value,
            'path' => 'private/never-emit-this-path.bin',
            'object_key_hash' => hash('sha256', 'health-metric'),
            'reason' => 'cleanup',
        ]);

        app(StorageHealthService::class)->check((int) $workspace->id);

        Event::assertDispatched(StorageTelemetryRecorded::class, fn (StorageTelemetryRecorded $event) =>
            $event->type === 'gauge'
            && $event->name === 'health.orphans.orphan_count'
            && $event->value === 1
            && ! str_contains(json_encode($event->context) ?: '', 'never-emit-this-path'));
    }

    public function test_observability_can_be_disabled_without_changing_storage_services(): void
    {
        config()->set('tetranyble-storage.observability.enabled', false);

        $this->assertInstanceOf(NullStorageTelemetry::class, app(StorageTelemetry::class));
    }

}
