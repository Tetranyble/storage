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

        Schema::create('activities', function (Blueprint $table) use ($userTable) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->nullable()->constrained($userTable)->nullOnDelete();
            $table->foreignId('workspace_id')->nullable()->index();
            $table->nullableMorphs('subject');
            $table->text('changes')->nullable();
            $table->string('description')->nullable();
            $table->string('type')->nullable();
            $table->text('meta')->nullable();
            $table->uuid('subject_uuid');
            $table->timestamps();
            $table->softDeletes();
            $table->index(
                ['workspace_id', 'subject_type', 'subject_id', 'type', 'created_at'],
                'activities_workspace_subject_visibility_idx',
            );
            $table->index(
                ['workspace_id', 'type', 'created_at', 'id'],
                'activities_workspace_type_cursor_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
