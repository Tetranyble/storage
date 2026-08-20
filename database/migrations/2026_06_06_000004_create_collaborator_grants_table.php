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

        Schema::create('collaborator_grants', function (Blueprint $table) use ($userTable) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->nullable();
            $table->morphs('collaboratable');
            $table->foreignId('user_id')->constrained($userTable)->cascadeOnDelete();
            $table->string('role', 32);
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamps();

            $table->unique([
                'workspace_id',
                'collaboratable_type',
                'collaboratable_id',
                'user_id',
            ], 'collaborator_grants_unique_resource_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaborator_grants');
    }
};
