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

        Schema::create('folders', function (Blueprint $table) use ($workspaceTable) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->nullable()->constrained($workspaceTable)->cascadeOnDelete();
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
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
    }
};
