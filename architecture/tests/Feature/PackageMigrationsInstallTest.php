<?php

namespace Tetranyble\Storage\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Tetranyble\Storage\StorageServiceProvider;

class PackageMigrationsInstallTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [StorageServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('tetranyble-storage.routes.enabled', false);
        $app['config']->set('tetranyble-storage.activities.enabled', false);
        $app['config']->set('tetranyble-storage.activities.load_migrations', false);
    }

    protected function setUp(): void
    {
        parent::setUp();
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }

    public function test_fresh_core_schema_installs_directly_in_hardened_shape(): void
    {
        foreach ($this->migrationFiles(__DIR__.'/../../database/migrations') as $path) {
            (require $path)->up();
        }

        foreach ([
            'folders',
            'media',
            'media_derivatives',
            'media_version_groups',
            'storage_orphans',
            'media_shares',
            'collaborator_grants',
            'resource_stars',
            'upload_sessions',
            'upload_session_chunks',
            'direct_upload_sessions',
            'storage_comments',
            'connected_drives',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' was not created.');
        }

        $this->assertFalse(Schema::hasColumn('media', 'thumbnail_path'));
        foreach ([
            'detected_mime_type',
            'processing_status',
            'processing_attempts',
            'processing_dispatch_attempts',
            'processing_dispatched_at',
            'processing_available_at',
            'scan_engine',
            'scan_signature',
            'quarantined_at',
            'direct_upload_session_uuid',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('media', $column), 'media.'.$column.' is missing.');
        }

        foreach (['next_attempt_at', 'abandoned_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('storage_orphans', $column), 'storage_orphans.'.$column.' is missing.');
        }

        $mediaSize = collect(Schema::getColumns('media'))->firstWhere('name', 'size');
        $this->assertIsArray($mediaSize);
        $this->assertMatchesRegularExpression('/int/i', (string) ($mediaSize['type_name'] ?? $mediaSize['type'] ?? ''));

        $this->assertIndexes('folders', [
            'folders_workspace_visibility_idx',
            'folders_workspace_name_cursor_idx',
            'folders_workspace_deleted_cursor_idx',
        ]);
        $this->assertIndexes('media', [
            'media_version_group_number_unique',
            'media_workspace_visibility_idx',
            'media_workspace_processing_status_index',
            'media_workspace_scan_status_index',
            'media_workspace_updated_cursor_idx',
            'media_workspace_created_cursor_idx',
            'media_direct_upload_session_unique',
            'media_processing_recovery_idx',
            'media_workspace_deleted_cursor_idx',
            'media_workspace_temporary_retention_idx',
        ]);
        $this->assertIndexes('storage_orphans', ['storage_orphans_retry_idx', 'storage_orphans_due_idx']);
        $this->assertIndexes('media_derivatives', [
            'media_derivative_variant_unique',
            'media_derivative_primary_idx',
            'media_derivative_workspace_kind_idx',
        ]);
        $this->assertIndexes('upload_sessions', [
            'upload_sessions_active_identifier_unique',
            'upload_sessions_workspace_identifier_status_idx',
            'upload_sessions_workspace_status_updated_idx',
            'upload_sessions_workspace_status_expiry_idx',
        ]);
        $this->assertIndexes('direct_upload_sessions', [
            'direct_upload_sessions_expiry_idx',
            'direct_upload_sessions_owner_idx',
            'direct_upload_sessions_workspace_status_updated_idx',
        ]);
        $this->assertIndexes('collaborator_grants', [
            'collaborator_grants_workspace_user_resource_idx',
            'collaborator_grants_workspace_user_resource_created_idx',
        ]);
        $this->assertIndexes('resource_stars', ['resource_stars_workspace_user_resource_created_idx']);
        $this->assertIndexes('connected_drives', ['connected_drives_workspace_status_expiry_idx']);
    }

    public function test_optional_activity_schema_installs_with_final_visibility_indexes(): void
    {
        (require __DIR__.'/../../database/migrations/activities/2026_06_06_000005_create_activities_table.php')->up();

        $this->assertTrue(Schema::hasTable('activities'));
        $this->assertIndexes('activities', [
            'activities_workspace_subject_visibility_idx',
            'activities_workspace_type_cursor_idx',
        ]);
    }

    /** @param list<string> $expected */
    private function assertIndexes(string $table, array $expected): void
    {
        $indexes = collect(Schema::getIndexes($table))->pluck('name')->all();
        foreach ($expected as $index) {
            $this->assertContains($index, $indexes, $table.' is missing '.$index);
        }
    }

    /** @return list<string> */
    private function migrationFiles(string $directory): array
    {
        $files = glob($directory.'/*_*.php') ?: [];
        sort($files);
        return array_values(array_filter($files, fn (string $path): bool => ! str_contains($path, '/activities/')));
    }
}
