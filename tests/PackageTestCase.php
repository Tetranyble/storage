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

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

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

        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $this->rebuildPackageSchema();
    }

    private function rebuildPackageSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'upload_session_chunks',
            'upload_sessions',
            'storage_comments',
            'connected_drives',
            'resource_stars',
            'activities',
            'collaborator_grants',
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
            $table->string('thumbnail_path')->nullable();
            $table->double('size')->nullable();
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
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->timestamp('uploaded_at')->nullable();
            $table->string('virus_scan_status', 32)->default('pending');
            $table->json('custom_properties')->nullable();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->string('access_scope', 32)->default('workspace');
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('previous_version_id')->references('id')->on('media')->nullOnDelete();
            $table->index('workspace_id');
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
        });

        Schema::create('resource_stars', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->morphs('starable');
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id', 'starable_type', 'starable_id'], 'resource_stars_unique_user_resource');
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

            $table->index(['workspace_id', 'identifier'], 'upload_sessions_workspace_identifier_idx');
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
    }
}
