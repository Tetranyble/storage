<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_derivatives', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->unsignedBigInteger('workspace_id')->nullable()->index();
            $table->string('kind', 32);
            $table->string('variant', 64)->default('default');
            $table->string('format', 16);
            $table->string('mime_type', 191);
            $table->string('disk', 64);
            $table->text('path');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('sha256', 64);
            $table->boolean('is_primary')->default(false);
            $table->timestamp('generated_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['media_id', 'kind', 'variant', 'format'], 'media_derivative_variant_unique');
            $table->index(['media_id', 'kind', 'is_primary'], 'media_derivative_primary_idx');
            $table->index(['workspace_id', 'kind', 'created_at'], 'media_derivative_workspace_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_derivatives');
    }
};
