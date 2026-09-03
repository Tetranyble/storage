<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_orphans', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->unsignedBigInteger('workspace_id')->nullable()->index();
            $table->string('disk', 64);
            $table->text('path');
            $table->string('object_key_hash', 64)->unique();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('reason', 64)->default('cleanup');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_orphans');
    }
};
