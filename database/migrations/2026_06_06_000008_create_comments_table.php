<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->nullableMorphs('commentable');
            $table->foreignId('workspace_id')->nullable()->index();
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
