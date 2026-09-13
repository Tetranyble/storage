<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->nullable();
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
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
    }
};
