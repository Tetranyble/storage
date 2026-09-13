<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support;

use Tetranyble\Storage\Modules\Access\Domain\Enums\AccessScope;
use Tetranyble\Storage\Modules\Activity\Infrastructure\Persistence\Eloquent\Models\Activity;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Persistence\Eloquent\Models\MediaShare;
use Tetranyble\Storage\Modules\Workspace\Application\ReadModel\ActivityView;
use Tetranyble\Storage\Modules\Workspace\Application\ReadModel\FileView;
use Tetranyble\Storage\Modules\Workspace\Application\ReadModel\FolderView;

final class WorkspaceReadProjector
{
    public function file(Media $media, bool $includeDeletedAt = false): array
    {
        return $this->fileView($media, $includeDeletedAt)->toArray();
    }

    public function fileView(Media $media, bool $includeDeletedAt = false): FileView
    {
        $dto = [
            'id' => $media->id,
            'uuid' => $media->uuid,
            'name' => $media->original_name ?: basename((string) $media->path) ?: 'untitled',
            'description' => $media->description,
            'path' => $media->path,
            'mime_type' => $media->mime_type,
            'size' => (int) ($media->size ?? 0),
            'current' => (bool) $media->current,
            'version_number' => (int) ($media->version_number ?? 1),
            'version_group_uuid' => $media->version_group_uuid,
            'previous_version_id' => $media->previous_version_id,
            'url' => $media->url,
            'signed_url' => $media->signed_url,
            'folder_id' => $media->folder_id,
            'access_scope' => $media->access_scope?->value ?? AccessScope::default()->value,
            'effective_role' => null,
            'uploaded_by' => $media->uploaded_by,
            'created_at' => optional($media->created_at)?->toIso8601String(),
            'updated_at' => optional($media->updated_at)?->toIso8601String(),
            'shares' => $media->relationLoaded('shares')
                ? $media->shares->map(fn (MediaShare $share) => [
                    'id' => $share->id,
                    'token' => $share->token,
                    'access_level' => $share->access_level,
                    'expires_at' => optional($share->expires_at)?->toIso8601String(),
                    'max_downloads' => $share->max_downloads,
                    'downloads_count' => $share->downloads_count,
                    'requires_password' => (bool) $share->requires_password,
                ])->values()->all()
                : [],
        ];
        if ($includeDeletedAt) {
            $dto['deleted_at'] = optional($media->deleted_at)?->toIso8601String();
        }
        return new FileView($dto);
    }

    public function folder(Folder $folder, bool $includeDeletedAt = false): array
    {
        return $this->folderView($folder, $includeDeletedAt)->toArray();
    }

    public function folderView(Folder $folder, bool $includeDeletedAt = false): FolderView
    {
        $dto = [
            'id' => $folder->id,
            'uuid' => $folder->uuid,
            'name' => $folder->name,
            'path' => $this->relativePath($folder),
            'access_scope' => $folder->access_scope?->value ?? AccessScope::default()->value,
            'is_archived' => $folder->is_archived,
            'created_at' => optional($folder->created_at)?->toIso8601String(),
            'updated_at' => optional($folder->updated_at)?->toIso8601String(),
        ];
        if ($includeDeletedAt) {
            $dto['deleted_at'] = optional($folder->deleted_at)?->toIso8601String();
        }
        return new FolderView($dto);
    }

    public function activity(Activity $activity): array
    {
        return $this->activityView($activity)->toArray();
    }

    public function activityView(Activity $activity): ActivityView
    {
        return new ActivityView([
            'id' => $activity->id,
            'uuid' => $activity->uuid,
            'type' => $activity->type,
            'description' => $activity->description,
            'subject_id' => $activity->subject_id,
            'subject_type' => $activity->subject_type,
            'actor_user_id' => $activity->user_id,
            'meta' => is_array($activity->meta) ? $activity->meta : [],
            'changes' => is_array($activity->changes) ? $activity->changes : [],
            'created_at' => optional($activity->created_at)?->toIso8601String(),
        ]);
    }

    public function relativePath(Folder $folder): string
    {
        if ($folder->is_root || $folder->path === 'root') {
            return '';
        }
        return trim((string) str($folder->path)->after('root/'), '/');
    }
}
