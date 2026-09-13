<?php

namespace Tetranyble\Storage\Tests;

use Tetranyble\Storage\StorageServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

abstract class PackageTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function getPackageProviders($app): array
    {
        return [StorageServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('tetranyble-storage.activities.enabled', true);
        $app['config']->set('tetranyble-storage.activities.load_migrations', true);
        $app['config']->set('tetranyble-storage.routes.enabled', true);
        // Keep legacy tests deterministic; dedicated processing tests enable
        // auto-dispatch/scanning explicitly. Package production default remains true.
        $app['config']->set('tetranyble-storage.processing.auto_dispatch', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $connection = (string) (getenv('STORAGE_TEST_DB_CONNECTION') ?: 'sqlite');

        if ($connection === 'sqlite') {
            config()->set('database.connections.sqlite', [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ]);
        } elseif ($connection === 'pgsql') {
            config()->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'host' => getenv('STORAGE_TEST_DB_HOST') ?: '127.0.0.1',
                'port' => getenv('STORAGE_TEST_DB_PORT') ?: '5432',
                'database' => getenv('STORAGE_TEST_DB_DATABASE') ?: 'storage_test',
                'username' => getenv('STORAGE_TEST_DB_USERNAME') ?: 'postgres',
                'password' => getenv('STORAGE_TEST_DB_PASSWORD') ?: 'postgres',
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ]);
        } elseif ($connection === 'mysql') {
            config()->set('database.connections.mysql', [
                'driver' => 'mysql',
                'host' => getenv('STORAGE_TEST_DB_HOST') ?: '127.0.0.1',
                'port' => getenv('STORAGE_TEST_DB_PORT') ?: '3306',
                'database' => getenv('STORAGE_TEST_DB_DATABASE') ?: 'storage_test',
                'username' => getenv('STORAGE_TEST_DB_USERNAME') ?: 'root',
                'password' => getenv('STORAGE_TEST_DB_PASSWORD') ?: 'root',
                'unix_socket' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
            ]);
        } else {
            throw new \RuntimeException('Unsupported STORAGE_TEST_DB_CONNECTION: '.$connection);
        }

        config()->set('database.default', $connection);
        DB::purge($connection);
        DB::reconnect($connection);

        config()->set('filesystems.default', 'local');
        config()->set('filesystems.disks.local', [
            'driver' => 'local',
            'root'   => storage_path('app'),
            'throw'  => false,
        ]);
        config()->set('filesystems.disks.public', [
            'driver'     => 'local',
            'root'       => storage_path('app/public'),
            'url'        => '/storage',
            'visibility' => 'public',
            'throw'      => false,
        ]);
        config()->set('filesystems.disks.s3-private', [
            'driver' => 'local',
            'root' => storage_path('app/s3-private'),
            'throw' => false,
        ]);
        config()->set('filesystems.disks.s3-public', [
            'driver' => 'local',
            'root' => storage_path('app/s3-public'),
            'url' => 'https://storage.example.test',
            'visibility' => 'public',
            'throw' => false,
        ]);

        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $this->rebuildPackageSchema();
    }

    private function rebuildPackageSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'direct_upload_sessions',
            'storage_orphans',
            'upload_session_chunks',
            'upload_sessions',
            'storage_comments',
            'connected_drives',
            'resource_stars',
            'activities',
            'collaborator_grants',
            'media_derivatives',
            'media_version_groups',
            'media_shares',
            'media',
            'folders',
            'dummy_mediable_models',
            'loans',
            'users',
            'workspaces',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();

        Schema::create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->uuid();
            $table->string('name');
            $table->unsignedBigInteger('storage_quota_bytes')->default(2 * 1024 * 1024 * 1024);
            $table->unsignedBigInteger('storage_used_bytes')->default(0);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->uuid();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('loans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('dummy_mediable_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('folders', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('folders')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('path');
            $table->string('access_scope', 32)->default('workspace');
            $table->boolean('is_root')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['workspace_id', 'path']);
            $table->index(['workspace_id', 'parent_id', 'deleted_at'], 'folders_workspace_parent_deleted_idx');
            $table->index(['workspace_id', 'deleted_at', 'access_scope', 'id'], 'folders_workspace_visibility_idx');
            $table->index(['workspace_id', 'deleted_at', 'name', 'id'], 'folders_workspace_name_cursor_idx');
            $table->index(['workspace_id', 'deleted_at', 'id'], 'folders_workspace_deleted_cursor_idx');
        });

        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->nullableMorphs('mediable');
            $table->foreignId('folder_id')->nullable()->constrained('folders')->nullOnDelete();
            $table->text('description')->nullable();
            $table->text('attribution')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('use')->default('GENERAL');
            $table->string('module')->nullable();
            $table->string('upload_strategy', 32)->default('single');
            $table->string('disk')->default('public');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->boolean('current')->default(false);
            $table->uuid('version_group_uuid')->nullable()->index();
            $table->unsignedInteger('version_number')->default(1);
            $table->unsignedBigInteger('previous_version_id')->nullable();
            $table->boolean('is_temporary')->default(false);
            $table->timestamp('temporary_expires_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->string('status')->default('PENDING');
            $table->text('error')->nullable();
            $table->unsignedBigInteger('inserted_items')->default(0);
            $table->unsignedBigInteger('skipped_items')->default(0);
            $table->string('original_name')->nullable();
            $table->string('sha256', 90)->nullable()->index();
            $table->uuid('direct_upload_session_uuid')->nullable()->unique('media_direct_upload_session_unique');
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->timestamp('uploaded_at')->nullable();
            $table->string('virus_scan_status', 32)->default('pending');
            $table->string('detected_mime_type', 191)->nullable();
            $table->string('processing_status', 32)->default('pending');
            $table->unsignedInteger('processing_attempts')->default(0);
            $table->unsignedInteger('processing_dispatch_attempts')->default(0);
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_dispatched_at')->nullable();
            $table->timestamp('processing_available_at')->nullable();
            $table->timestamp('processing_completed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamp('scan_started_at')->nullable();
            $table->timestamp('scan_completed_at')->nullable();
            $table->string('scan_engine', 64)->nullable();
            $table->string('scan_signature', 191)->nullable();
            $table->timestamp('quarantined_at')->nullable();
            $table->string('quarantine_reason', 64)->nullable();
            $table->json('custom_properties')->nullable();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->string('access_scope', 32)->default('workspace');
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('previous_version_id')->references('id')->on('media')->nullOnDelete();
            $table->index('workspace_id');
            $table->index(['workspace_id', 'folder_id', 'deleted_at'], 'media_workspace_folder_deleted_idx');
            $table->index(['workspace_id', 'archived_at', 'deleted_at'], 'media_workspace_archived_deleted_idx');
            $table->index(['workspace_id', 'current', 'deleted_at'], 'media_workspace_current_deleted_idx');
            $table->index(['workspace_id', 'uploaded_at'], 'media_workspace_uploaded_at_idx');
            $table->index(['workspace_id', 'access_scope', 'deleted_at', 'folder_id'], 'media_workspace_visibility_idx');
            $table->index(['workspace_id', 'processing_status'], 'media_workspace_processing_status_index');
            $table->index(['workspace_id', 'virus_scan_status'], 'media_workspace_scan_status_index');
            $table->index(['workspace_id', 'deleted_at', 'updated_at', 'id'], 'media_workspace_updated_cursor_idx');
            $table->index(['workspace_id', 'deleted_at', 'created_at', 'id'], 'media_workspace_created_cursor_idx');
            $table->index(['workspace_id', 'deleted_at', 'id'], 'media_workspace_deleted_cursor_idx');
            $table->index(['workspace_id', 'is_temporary', 'temporary_expires_at', 'id'], 'media_workspace_temporary_retention_idx');
            $table->index(['processing_status', 'processing_available_at', 'processing_dispatched_at', 'id'], 'media_processing_recovery_idx');
        });

        Schema::table('media', function (Blueprint $table): void {
            $table->unique(
                ['version_group_uuid', 'version_number'],
                'media_version_group_number_unique'
            );
        });

        Schema::create('media_derivatives', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->unsignedBigInteger('workspace_id')->nullable()->index();
            $table->string('kind', 32);
            $table->string('variant', 64)->default('default');
            $table->string('format', 16);
            $table->string('mime_type', 191);
            $table->string('disk', 64);
            $table->text('path');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('sha256', 64);
            $table->boolean('is_primary')->default(false);
            $table->timestamp('generated_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['media_id', 'kind', 'variant', 'format'], 'media_derivative_variant_unique');
            $table->index(['media_id', 'kind', 'is_primary'], 'media_derivative_primary_idx');
            $table->index(['workspace_id', 'kind', 'created_at'], 'media_derivative_workspace_kind_idx');
        });

        Schema::create('media_version_groups', function (Blueprint $table): void {
            $table->uuid('version_group_uuid')->primary();
            $table->unsignedInteger('next_version_number')->default(2);
            $table->unsignedBigInteger('current_media_id')->nullable()->index();
            $table->timestamps();
            $table->foreign('current_media_id')->references('id')->on('media')->nullOnDelete();
        });

        Schema::create('storage_orphans', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->unsignedBigInteger('workspace_id')->nullable()->index();
            $table->string('disk', 64);
            $table->text('path');
            $table->string('object_key_hash', 64)->unique();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('reason', 64)->default('cleanup');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamps();
            $table->index(['attempts', 'last_attempt_at'], 'storage_orphans_retry_idx');
            $table->index(['abandoned_at', 'next_attempt_at', 'id'], 'storage_orphans_due_idx');
        });

        Schema::create('media_shares', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
            $table->morphs('shareable');
            $table->string('token')->unique();
            $table->string('access_level')->default('view');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_downloads')->nullable();
            $table->unsignedInteger('downloads_count')->default(0);
            $table->boolean('requires_password')->default(false);
            $table->string('password_hash')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('activities', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->text('changes')->nullable();
            $table->string('description')->nullable();
            $table->string('type')->nullable();
            $table->text('meta')->nullable();
            $table->uuid('subject_uuid');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['workspace_id', 'subject_type', 'subject_id', 'type', 'created_at'], 'activities_workspace_subject_visibility_idx');
            $table->index(['workspace_id', 'type', 'created_at', 'id'], 'activities_workspace_type_cursor_idx');
        });

        Schema::create('collaborator_grants', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
            $table->morphs('collaboratable');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32);
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamps();
            $table->unique([
                'workspace_id',
                'collaboratable_type',
                'collaboratable_id',
                'user_id',
            ], 'collaborator_grants_unique_resource_user');
            $table->index(['workspace_id', 'user_id', 'collaboratable_type', 'collaboratable_id'], 'collaborator_grants_workspace_user_resource_idx');
            $table->index(['workspace_id', 'user_id', 'collaboratable_type', 'collaboratable_id', 'created_at'], 'collaborator_grants_workspace_user_resource_created_idx');
        });

        Schema::create('resource_stars', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->morphs('starable');
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id', 'starable_type', 'starable_id'], 'resource_stars_unique_user_resource');
            $table->index(['workspace_id', 'user_id', 'starable_type', 'starable_id', 'created_at'], 'resource_stars_workspace_user_resource_created_idx');
        });

        Schema::create('connected_drives', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
            $table->string('provider');
            $table->string('name');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->text('credentials')->nullable();
            $table->string('status')->default('connected');
            $table->boolean('is_default')->default(false);
            $table->string('default_slot', 16)->nullable();
            $table->string('access_scope', 32)->default('workspace');
            $table->text('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['workspace_id', 'default_slot'], 'connected_drives_one_default_per_workspace');
            $table->index('default_slot');
            $table->index(['workspace_id', 'provider']);
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'status', 'token_expires_at'], 'connected_drives_workspace_status_expiry_idx');
        });

        Schema::create('storage_comments', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->nullableMorphs('commentable');
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('storage_comments')->nullOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('upload_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('folders')->nullOnDelete();
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('identifier');
            $table->string('active_identifier_hash', 64)->nullable()->unique('upload_sessions_active_identifier_unique');
            $table->string('fingerprint', 64);
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('disk', 64)->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('total_chunks');
            $table->unsignedBigInteger('total_size')->nullable();
            $table->unsignedInteger('chunk_size')->nullable();
            $table->unsignedInteger('received_chunks')->default(0);
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->json('upload_options');
            $table->string('conflict_reason')->nullable();
            $table->json('conflict_meta')->nullable();
            $table->timestamp('session_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('last_chunk_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'identifier', 'status'], 'upload_sessions_workspace_identifier_status_idx');
            $table->index(['workspace_id', 'status', 'updated_at', 'id'], 'upload_sessions_workspace_status_updated_idx');
            $table->index(['workspace_id', 'status', 'session_expires_at', 'id'], 'upload_sessions_workspace_status_expiry_idx');
        });

        Schema::create('upload_session_chunks', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('upload_session_id')->constrained('upload_sessions')->cascadeOnDelete();
            $table->unsignedInteger('chunk_number');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->string('path');
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->unique(['upload_session_id', 'chunk_number'], 'upload_session_chunks_unique_chunk');
        });

        Schema::create('direct_upload_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('folders')->nullOnDelete();
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('provider', 32)->default('s3');
            $table->string('mode', 32)->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->string('disk', 64);
            $table->text('object_key');
            $table->string('object_key_hash', 64)->unique();
            $table->string('original_name');
            $table->string('mime_type', 191)->nullable();
            $table->unsignedBigInteger('expected_size');
            $table->string('expected_sha256', 64)->nullable();
            $table->text('provider_upload_id')->nullable();
            $table->unsignedBigInteger('part_size')->nullable();
            $table->unsignedInteger('total_parts')->nullable();
            $table->unsignedBigInteger('reserved_bytes')->default(0);
            $table->json('upload_options');
            $table->timestamp('session_expires_at')->nullable();
            $table->timestamp('finalizing_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->boolean('cleanup_pending')->default(false)->index();
            $table->unsignedInteger('cleanup_attempts')->default(0);
            $table->text('cleanup_error')->nullable();
            $table->timestamp('cleanup_attempted_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status', 'session_expires_at'], 'direct_upload_sessions_expiry_idx');
            $table->index(['workspace_id', 'user_id', 'status'], 'direct_upload_sessions_owner_idx');
            $table->index(['workspace_id', 'status', 'updated_at', 'id'], 'direct_upload_sessions_workspace_status_updated_idx');
        });
    }
}
