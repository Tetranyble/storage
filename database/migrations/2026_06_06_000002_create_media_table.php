<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tetranyble\Storage\Support\StorageConfig;

return new class extends Migration
{
    public function up(): void
    {
        $workspaceTable = StorageConfig::workspacesTable();

        Schema::create('media', function (Blueprint $table) use ($workspaceTable) {
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
            $table->foreignId('workspace_id')->nullable()->constrained($workspaceTable)->nullOnDelete();
            $table->string('access_scope', 32)->default('workspace');
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('previous_version_id')->references('id')->on('media')->nullOnDelete();
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
