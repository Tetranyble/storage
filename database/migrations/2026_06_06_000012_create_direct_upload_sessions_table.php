<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tetranyble\Storage\Support\StorageConfig;

return new class extends Migration
{
    public function up(): void
    {
        $userTable = StorageConfig::usersTable();

        Schema::create('direct_upload_sessions', function (Blueprint $table) use ($userTable): void {
            $table->id();
            $table->uuid()->unique();
            $table->unsignedBigInteger('workspace_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained($userTable)->nullOnDelete();
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
            $table->timestamp('session_expires_at')->nullable()->index();
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

    public function down(): void
    {

        Schema::dropIfExists('direct_upload_sessions');
    }
};
