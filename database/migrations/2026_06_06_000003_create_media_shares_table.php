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

        Schema::create('media_shares', function (Blueprint $table) use ($workspaceTable) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->nullable()->constrained($workspaceTable)->cascadeOnDelete();
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
    }

    public function down(): void
    {
        Schema::dropIfExists('media_shares');
    }
};
