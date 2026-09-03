<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::table('media')
            ->select('version_group_uuid', 'version_number')
            ->selectRaw('COUNT(*) AS aggregate')
            ->whereNotNull('version_group_uuid')
            ->groupBy('version_group_uuid', 'version_number')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate) {
            throw new \RuntimeException(sprintf(
                'Cannot harden media version integrity: version group [%s] contains duplicate version number [%s]. Reconcile duplicate versions before running this migration.',
                $duplicate->version_group_uuid,
                $duplicate->version_number,
            ));
        }

        Schema::create('media_version_groups', function (Blueprint $table): void {
            $table->uuid('version_group_uuid')->primary();
            $table->unsignedInteger('next_version_number')->default(2);
            $table->unsignedBigInteger('current_media_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('current_media_id')
                ->references('id')
                ->on('media')
                ->nullOnDelete();
        });

        $groupUuids = DB::table('media')
            ->whereNotNull('version_group_uuid')
            ->distinct()
            ->orderBy('version_group_uuid')
            ->pluck('version_group_uuid');

        foreach ($groupUuids as $groupUuid) {
            $groupUuid = (string) $groupUuid;

            $maxVersion = (int) (DB::table('media')
                ->where('version_group_uuid', $groupUuid)
                ->max('version_number') ?? 0);

            $current = DB::table('media')
                ->where('version_group_uuid', $groupUuid)
                ->where('current', true)
                ->whereNull('deleted_at')
                ->orderByDesc('version_number')
                ->orderByDesc('id')
                ->first();

            DB::table('media')
                ->where('version_group_uuid', $groupUuid)
                ->where('current', true)
                ->when($current, fn ($query) => $query->where('id', '<>', $current->id))
                ->update(['current' => false]);

            DB::table('media_version_groups')->insert([
                'version_group_uuid' => $groupUuid,
                'next_version_number' => max(1, $maxVersion + 1),
                'current_media_id' => $current?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('media', function (Blueprint $table): void {
            $table->unique(
                ['version_group_uuid', 'version_number'],
                'media_version_group_number_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropUnique('media_version_group_number_unique');
        });

        Schema::dropIfExists('media_version_groups');
    }
};
