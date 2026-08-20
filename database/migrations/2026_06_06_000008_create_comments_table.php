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

        Schema::create('storage_comments', function (Blueprint $table) use ($workspaceTable) {
            $table->id();
            $table->uuid()->unique();
            $table->nullableMorphs('commentable');
            $table->foreignId('workspace_id')->nullable()->constrained($workspaceTable)->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->foreignId('parent_id')->nullable()->constrained('storage_comments')->nullOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_comments');
    }
};
