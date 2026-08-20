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
        $workspaceTable = StorageConfig::workspacesTable();

        Schema::create('activities', function (Blueprint $table) use ($userTable, $workspaceTable) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->nullable()->constrained($userTable)->nullOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained($workspaceTable)->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->text('changes')->nullable();
            $table->string('description')->nullable();
            $table->string('type')->nullable();
            $table->text('meta')->nullable();
            $table->uuid('subject_uuid');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
