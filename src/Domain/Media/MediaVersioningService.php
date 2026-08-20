<?php

namespace Tetranyble\Storage\Domain\Media;

use Tetranyble\Storage\Contracts\ActivityLogger;
use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\FileSystem\StorageService;
use Tetranyble\Storage\Enums\MediaRevisionEventType;
use Tetranyble\Storage\Models\Activity;
use Tetranyble\Storage\Models\Media;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Owns all versioning semantics for Media records.
 *
 * Version fields (current, version_group_uuid, version_number, previous_version_id)
 * are NOT in Media::$fillable. The only safe path to write them is through this
 * service, which uses forceFill() internally.
 */
class MediaVersioningService
{
    public function __construct(
        private readonly FileSystemContract $files,
        private readonly StorageService     $storage,
        private readonly ActivityLogger     $activityLogger,
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
        return Media::query()
            ->where('version_group_uuid', $this->ensureVersionSeed($media))
            ->where('current', true)
            ->latest('version_number')
            ->latest('id')
            ->first();
    }

    /**
     * Full audit trail for every version in the group.
     */
    public function activity(Media $media): Collection
    {
        $groupUuid = $this->ensureVersionSeed($media);
        $versionIds = Media::withTrashed()
            ->where('version_group_uuid', $groupUuid)
            ->pluck('id');

        return Activity::query()
            ->where('subject_type', $media->getMorphClass())
            ->whereIn('subject_id', $versionIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    // ---------------------------------------------------------------
    // Mutation
    // ---------------------------------------------------------------

    /**
     * Permanently delete a non-current version.
     *
     * Throws if the version is the active one — restore another revision first.
     * Throws if it is the only version in the group.
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

        $path = $version->path;
        $disk = $version->disk;
        $size = (int) ($version->size ?? 0);

        // Remove physical file when it is not an external URL
        if ($path && ! $this->isExternalUrl($path) && $disk instanceof Disk) {
            $this->files->delete($path, $disk);
        }

        if ($size > 0) {
            $this->storage->decreaseUsage($workspace, $size);
        }

        $this->activityLogger->log(
            subject:     $version,
            type:        'storage.media.'.MediaRevisionEventType::DELETED->value,
            description: 'Media version permanently deleted.',
            actor:       $actor,
            meta:        [
                'version_group_uuid' => $version->version_group_uuid,
                'version_number'     => $version->version_number,
            ],
            workspaceId: $workspace->id,
        );

        $version->forceDelete();
    }

    // ---------------------------------------------------------------
    // Internal helpers — used by MediaService during upload / restore
    // ---------------------------------------------------------------

    /**
     * Compute the [groupUuid, versionNumber, previousVersionId] triple for a new upload.
     * When superseding, the previous version is immediately marked non-current.
     *
     * This method is intentionally package-internal — call it from MediaService only.
     *
     * @return array{0: string, 1: int, 2: int|null}
     */
    public function prepareContext(?Media $replacedMedia, bool $supersede = true): array
    {
        if ($replacedMedia === null) {
            return [(string) Str::uuid(), 1, null];
        }

        $groupUuid         = $this->ensureVersionSeed($replacedMedia);
        $nextVersionNumber = (int) Media::query()
            ->where('version_group_uuid', $groupUuid)
            ->max('version_number') + 1;

        if ($supersede && $replacedMedia->current) {
            $replacedMedia->forceFill(['current' => false])->save();
        }

        return [$groupUuid, $nextVersionNumber, $replacedMedia->id];
    }

    /**
     * Write version fields onto an already-persisted Media record.
     * Uses forceFill() to bypass $fillable — only call from MediaService.
     *
     * @param array{0: string, 1: int, 2: int|null} $context From prepareContext()
     */
    public function applyContext(Media $media, array $context, bool $isCurrent = true): void
    {
        [$groupUuid, $versionNumber, $previousVersionId] = $context;

        if ($isCurrent) {
            Media::query()
                ->where('version_group_uuid', $groupUuid)
                ->whereKeyNot($media->getKey())
                ->update(['current' => false]);
        }

        $media->forceFill([
            'current'             => $isCurrent,
            'version_group_uuid'  => $groupUuid,
            'version_number'      => $versionNumber,
            'previous_version_id' => $previousVersionId,
        ])->save();
    }

    /**
     * Ensure the media record has a version_group_uuid seed.
     * Writes to the DB via forceFill() if not already set.
     */
    public function ensureVersionSeed(Media $media): string
    {
        if ($media->version_group_uuid && (int) $media->version_number >= 1) {
            return $media->version_group_uuid;
        }

        $groupUuid     = $media->version_group_uuid ?: (string) ($media->uuid ?: Str::uuid());
        $versionNumber = max(1, (int) ($media->version_number ?: 1));

        $media->forceFill([
            'version_group_uuid' => $groupUuid,
            'version_number'     => $versionNumber,
        ])->save();

        return $groupUuid;
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
