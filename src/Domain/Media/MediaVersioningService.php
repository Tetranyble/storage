<?php

namespace Tetranyble\Storage\Domain\Media;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tetranyble\Storage\Contracts\ActivityFeed;
use Tetranyble\Storage\Contracts\ActivityLogger;
use Tetranyble\Storage\Enums\MediaRevisionEventType;
use Tetranyble\Storage\Models\Media;

/**
 * Owns all versioning semantics for Media records.
 *
 * Version fields (current, version_group_uuid, version_number, previous_version_id)
 * are NOT in Media::$fillable. The only safe path to write them is through this
 * service, which uses forceFill() internally.
 *
 * Version numbers are allocated through media_version_groups. The group row acts
 * as the serialization point for concurrent revisions and current-version changes.
 */
class MediaVersioningService
{
    private const VERSION_GROUPS_TABLE = 'media_version_groups';

    public function __construct(
        private readonly MediaDeletionService $deletion,
        private readonly ActivityLogger $activityLogger,
        private readonly ActivityFeed $activityFeed,
    ) {}

    // ---------------------------------------------------------------
    // Read
    // ---------------------------------------------------------------

    /**
     * All versions in the same group, newest first.
     */
    public function versions(Media $media): Collection
    {
        return Media::withTrashed()
            ->where('version_group_uuid', $this->ensureVersionSeed($media))
            ->orderByDesc('version_number')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The currently active version within the group.
     */
    public function currentVersion(Media $media): ?Media
    {
        $groupUuid = $this->ensureVersionSeed($media);
        $this->ensureVersionRegistry($groupUuid);

        $currentMediaId = DB::table(self::VERSION_GROUPS_TABLE)
            ->where('version_group_uuid', $groupUuid)
            ->value('current_media_id');

        if ($currentMediaId !== null) {
            $current = Media::query()->find($currentMediaId);
            if ($current instanceof Media && $current->current) {
                return $current;
            }
        }

        $current = Media::query()
            ->where('version_group_uuid', $groupUuid)
            ->where('current', true)
            ->latest('version_number')
            ->latest('id')
            ->first();

        if ($current instanceof Media) {
            DB::table(self::VERSION_GROUPS_TABLE)
                ->where('version_group_uuid', $groupUuid)
                ->update([
                    'current_media_id' => $current->id,
                    'updated_at' => now(),
                ]);
        }

        return $current;
    }

    /**
     * Full audit trail for every version in the group.
     */
    public function activity(Media $media): Collection
    {
        $groupUuid = $this->ensureVersionSeed($media);

        return $this->activityFeed->forVersionGroup($media, $groupUuid);
    }

    // ---------------------------------------------------------------
    // Mutation
    // ---------------------------------------------------------------

    /**
     * Permanently delete a non-current version.
     */
    public function deleteVersion(Model $workspace, Media $version, Model $actor): void
    {
        $this->assertWorkspaceMedia($workspace, $version);

        if ($version->current) {
            throw new RuntimeException(
                'Cannot delete the current version. Restore a different revision first.'
            );
        }

        $groupCount = Media::withTrashed()
            ->where('version_group_uuid', $version->version_group_uuid)
            ->count();

        if ($groupCount <= 1) {
            throw new RuntimeException('Cannot delete the only version of a file.');
        }

        $versionGroupUuid = $version->version_group_uuid;
        $versionNumber = $version->version_number;

        $this->deletion->delete($version);

        $this->activityLogger->log(
            subject: $version,
            type: 'storage.media.'.MediaRevisionEventType::DELETED->value,
            description: 'Media version permanently deleted.',
            actor: $actor,
            meta: [
                'version_group_uuid' => $versionGroupUuid,
                'version_number' => $versionNumber,
            ],
            workspaceId: $workspace->id,
        );
    }

    // ---------------------------------------------------------------
    // Internal helpers — used by MediaService during upload / restore
    // ---------------------------------------------------------------

    /**
     * Reserve the [groupUuid, versionNumber, previousVersionId] triple for a new
     * upload. Reserving a number never changes which Media row is current; that
     * happens only when applyContext() successfully persists the replacement.
     *
     * @return array{0: string, 1: int, 2: int|null}
     */
    public function prepareContext(?Media $replacedMedia, bool $supersede = true): array
    {
        if ($replacedMedia === null) {
            return [(string) Str::uuid(), 1, null];
        }

        $groupUuid = $this->ensureVersionSeed($replacedMedia);
        $nextVersionNumber = $this->reserveNextVersionNumber($groupUuid);

        return [$groupUuid, $nextVersionNumber, $replacedMedia->id];
    }

    /**
     * Write version fields onto an already-persisted Media record.
     *
     * The version-group row is locked while changing current-version state, so
     * concurrent revisions cannot leave multiple rows marked current.
     *
     * @param array{0: string, 1: int, 2: int|null} $context
     */
    public function applyContext(Media $media, array $context, bool $isCurrent = true): void
    {
        [$groupUuid, $versionNumber, $previousVersionId] = $context;

        DB::transaction(function () use (
            $media,
            $groupUuid,
            $versionNumber,
            $previousVersionId,
            $isCurrent,
        ): void {
            $this->ensureVersionRegistry($groupUuid, max(2, $versionNumber + 1));

            DB::table(self::VERSION_GROUPS_TABLE)
                ->where('version_group_uuid', $groupUuid)
                ->lockForUpdate()
                ->first();

            if ($isCurrent) {
                Media::query()
                    ->where('version_group_uuid', $groupUuid)
                    ->whereKeyNot($media->getKey())
                    ->update(['current' => false]);
            }

            $media->forceFill([
                'current' => $isCurrent,
                'version_group_uuid' => $groupUuid,
                'version_number' => $versionNumber,
                'previous_version_id' => $previousVersionId,
            ])->save();

            if ($isCurrent) {
                DB::table(self::VERSION_GROUPS_TABLE)
                    ->where('version_group_uuid', $groupUuid)
                    ->update([
                        'current_media_id' => $media->id,
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    /**
     * Ensure the media record has a version_group_uuid seed and corresponding
     * allocator registry.
     */
    public function ensureVersionSeed(Media $media): string
    {
        if ($media->version_group_uuid && (int) $media->version_number >= 1) {
            $groupUuid = (string) $media->version_group_uuid;
            $this->ensureVersionRegistry($groupUuid, (int) $media->version_number + 1, $media->current ? $media->id : null);

            return $groupUuid;
        }

        $groupUuid = $media->version_group_uuid ?: (string) ($media->uuid ?: Str::uuid());
        $versionNumber = max(1, (int) ($media->version_number ?: 1));

        DB::transaction(function () use ($media, $groupUuid, $versionNumber): void {
            $media->forceFill([
                'version_group_uuid' => $groupUuid,
                'version_number' => $versionNumber,
            ])->save();

            $this->ensureVersionRegistry(
                $groupUuid,
                $versionNumber + 1,
                $media->current ? $media->id : null,
            );
        });

        return (string) $groupUuid;
    }

    // ---------------------------------------------------------------
    // Version allocator
    // ---------------------------------------------------------------

    private function reserveNextVersionNumber(string $groupUuid): int
    {
        return DB::transaction(function () use ($groupUuid): int {
            $this->ensureVersionRegistry($groupUuid);

            $group = DB::table(self::VERSION_GROUPS_TABLE)
                ->where('version_group_uuid', $groupUuid)
                ->lockForUpdate()
                ->first();

            if (! $group) {
                throw new RuntimeException('Unable to initialize media version allocator.');
            }

            $observedMax = (int) (Media::withTrashed()
                ->where('version_group_uuid', $groupUuid)
                ->max('version_number') ?? 0);

            $next = max((int) $group->next_version_number, $observedMax + 1, 1);

            DB::table(self::VERSION_GROUPS_TABLE)
                ->where('version_group_uuid', $groupUuid)
                ->update([
                    'next_version_number' => $next + 1,
                    'updated_at' => now(),
                ]);

            return $next;
        });
    }

    private function ensureVersionRegistry(
        string $groupUuid,
        ?int $minimumNextVersion = null,
        ?int $currentMediaId = null,
    ): void {
        $observedMax = (int) (Media::withTrashed()
            ->where('version_group_uuid', $groupUuid)
            ->max('version_number') ?? 0);

        $minimumNextVersion = max($minimumNextVersion ?? 1, $observedMax + 1, 1);

        if ($currentMediaId === null) {
            $currentMediaId = Media::query()
                ->where('version_group_uuid', $groupUuid)
                ->where('current', true)
                ->orderByDesc('version_number')
                ->orderByDesc('id')
                ->value('id');
        }

        DB::table(self::VERSION_GROUPS_TABLE)->insertOrIgnore([
            'version_group_uuid' => $groupUuid,
            'next_version_number' => $minimumNextVersion,
            'current_media_id' => $currentMediaId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(self::VERSION_GROUPS_TABLE)
            ->where('version_group_uuid', $groupUuid)
            ->where('next_version_number', '<', $minimumNextVersion)
            ->update([
                'next_version_number' => $minimumNextVersion,
                'updated_at' => now(),
            ]);

        if ($currentMediaId !== null) {
            DB::table(self::VERSION_GROUPS_TABLE)
                ->where('version_group_uuid', $groupUuid)
                ->whereNull('current_media_id')
                ->update([
                    'current_media_id' => $currentMediaId,
                    'updated_at' => now(),
                ]);
        }
    }

    // ---------------------------------------------------------------
    // Private
    // ---------------------------------------------------------------

    private function assertWorkspaceMedia(Model $workspace, Media $media): void
    {
        if ((int) ($media->workspace_id ?? 0) !== (int) $workspace->id) {
            abort(404);
        }
    }

    private function isExternalUrl(string $path): bool
    {
        if (str_starts_with($path, '//')) {
            return true;
        }

        return filter_var($path, FILTER_VALIDATE_URL) !== false;
    }
}
