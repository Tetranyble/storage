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

        Schema::create('upload_sessions', function (Blueprint $table) use ($userTable): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained($userTable)->nullOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('folders')->nullOnDelete();
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('identifier');
            $table->string('fingerprint', 64);
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('disk', 64)->nullable();
            $table->string('status', 32)->default('pending')->index();
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

    public function down(): void
    {
        Schema::dropIfExists('upload_session_chunks');
        Schema::dropIfExists('upload_sessions');
    }
};
