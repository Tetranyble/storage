<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->nullableMorphs('mediable');
            $table->foreignId('folder_id')->nullable()->constrained('folders')->nullOnDelete();
            $table->foreignId('workspace_id')->nullable();
            $table->text('description')->nullable();
            $table->text('attribution')->nullable();
            $table->string('mime_type', 191)->nullable();
            $table->string('detected_mime_type', 191)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('use')->default('GENERAL');
            $table->string('module')->nullable();
            $table->string('upload_strategy', 32)->default('single');
            $table->string('disk', 64)->default('public');
            $table->text('path')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('sha256', 64)->nullable()->index();
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->timestamp('uploaded_at')->nullable();
            $table->string('access_scope', 32)->default('workspace');

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

            $table->string('virus_scan_status', 32)->default('pending');
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

            $table->uuid('direct_upload_session_uuid')->nullable()->unique('media_direct_upload_session_unique');
            $table->json('custom_properties')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('previous_version_id')->references('id')->on('media')->nullOnDelete();
            $table->unique(['version_group_uuid', 'version_number'], 'media_version_group_number_unique');
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
            $table->index(
                ['processing_status', 'processing_available_at', 'processing_dispatched_at', 'id'],
                'media_processing_recovery_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
