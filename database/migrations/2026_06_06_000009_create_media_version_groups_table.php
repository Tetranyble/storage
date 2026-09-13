<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_version_groups', function (Blueprint $table): void {
            $table->uuid('version_group_uuid')->primary();
            $table->unsignedInteger('next_version_number')->default(2);
            $table->unsignedBigInteger('current_media_id')->nullable()->index();
            $table->timestamps();
            $table->foreign('current_media_id')->references('id')->on('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_version_groups');
    }
};
