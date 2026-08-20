<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
    }
};
