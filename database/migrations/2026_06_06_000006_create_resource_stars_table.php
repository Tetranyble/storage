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

        Schema::create('resource_stars', function (Blueprint $table) use ($userTable) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->nullable();
            $table->foreignId('user_id')->constrained($userTable)->cascadeOnDelete();
            $table->morphs('starable');
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id', 'starable_type', 'starable_id'], 'resource_stars_unique_user_resource');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_stars');
    }
};
