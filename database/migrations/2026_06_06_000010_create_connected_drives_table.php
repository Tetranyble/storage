<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_drives', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->nullable();
            $table->string('provider');                   // CloudProvider enum value
            $table->string('name');                       // user-given label
            $table->text('access_token')->nullable();     // encrypted
            $table->text('refresh_token')->nullable();    // encrypted
            $table->timestamp('token_expires_at')->nullable();
            $table->text('credentials')->nullable();      // encrypted JSON (S3: bucket/key/secret/region; GDrive/OD: root_drive_id etc.)
            $table->string('status')->default('connected');
            $table->boolean('is_default')->default(false);
            $table->string('default_slot', 16)->nullable();
            $table->string('access_scope', 32)->default('workspace');
            $table->text('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'provider']);
            $table->index(['workspace_id', 'status']);
            $table->index('default_slot');
            $table->unique(['workspace_id', 'default_slot'], 'connected_drives_one_default_per_workspace');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_drives');
    }
};
