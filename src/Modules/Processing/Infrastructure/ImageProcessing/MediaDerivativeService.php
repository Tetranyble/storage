<?php

namespace Tetranyble\Storage\Modules\Processing\Infrastructure\ImageProcessing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaDerivativeKind;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Persistence\Eloquent\Models\MediaDerivative;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageOrphanService;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageService;
use Tetranyble\Storage\Support\StorageConfig;

/**
 * Owns derivative persistence, quota accounting, and physical cleanup.
 *
 * Derivatives are first-class, rebuildable storage objects. The Media row never
 * mirrors derivative paths; every derivative carries its own disk/path/size.
 */
final class MediaDerivativeService
{
    public function __construct(
        private readonly FileSystemContract $files,
        private readonly StorageOrphanService $orphans,
        private readonly StorageService $storage,
    ) {}

    public function find(Media $media, MediaDerivativeKind $kind, string $variant, string $format): ?MediaDerivative
    {
        return $media->derivatives()
            ->where('kind', $kind->value)
            ->where('variant', $variant)
            ->where('format', $this->normalizeFormat($format))
            ->first();
    }

    public function primary(Media $media, MediaDerivativeKind $kind): ?MediaDerivative
    {
        return $media->derivatives()
            ->where('kind', $kind->value)
            ->where('is_primary', true)
            ->orderByDesc('id')
            ->first();
    }

    public function exists(MediaDerivative $derivative): bool
    {
        return $derivative->disk instanceof Disk
            && $derivative->path !== ''
            && $this->files->exists($derivative->path, $derivative->disk);
    }

    public function persistBinary(
        Media $media,
        MediaDerivativeKind $kind,
        string $variant,
        string $format,
        string $mimeType,
        string $binary,
        int $width,
        int $height,
        bool $primary = false,
        array $metadata = [],
    ): MediaDerivative {
        $disk = $media->disk instanceof Disk ? $media->disk : null;
        if ($disk === null) {
            throw new RuntimeException('Media derivative storage requires a concrete package disk.');
        }

        $format = $this->normalizeFormat($format);
        $sha256 = hash('sha256', $binary);
        $path = $this->path($media, $kind, $variant, $format, $sha256);
        $workspace = $media->workspace_id ? StorageConfig::findWorkspace($media->workspace_id) : null;
        $workspaceId = $workspace?->getKey() ? (int) $workspace->getKey() : null;
        $newSize = strlen($binary);
        $existing = $this->find($media, $kind, $variant, $format);
        $oldSize = (int) ($existing?->size ?? 0);
        $oldPath = $existing?->path;
        $oldDisk = $existing?->disk instanceof Disk ? $existing->disk : null;
        $delta = $newSize - $oldSize;

        if ($workspace && $delta > 0) {
            $this->storage->increaseUsage($workspace, $delta);
        }

        $candidateAlreadyExists = $existing !== null
            && $existing->path === $path
            && $existing->disk === $disk
            && $this->files->exists($path, $disk);

        if (! $candidateAlreadyExists) {
            try {
                if (! $this->files->put($path, $binary, $disk)) {
                    throw new RuntimeException('Derivative storage write returned false.');
                }
            } catch (Throwable $exception) {
                if ($workspace && $delta > 0) {
                    $this->storage->decreaseUsage($workspace, $delta);
                }
                $this->orphans->deleteOrTrack($disk, $path, $workspaceId, $newSize, 'derivative_write_rollback');
                throw $exception;
            }
        }

        try {
            $derivative = DB::transaction(function () use (
                $media,
                $kind,
                $variant,
                $format,
                $mimeType,
                $newSize,
                $width,
                $height,
                $primary,
                $metadata,
                $disk,
                $path,
                $workspaceId,
                $sha256,
            ): MediaDerivative {
                // Serialize derivative mutations for one Media row. This makes
                // primary selection deterministic across concurrent formats.
                Media::query()->lockForUpdate()->findOrFail($media->getKey());

                if ($primary) {
                    MediaDerivative::query()
                        ->where('media_id', $media->getKey())
                        ->where('kind', $kind->value)
                        ->update(['is_primary' => false]);
                }

                return MediaDerivative::query()->updateOrCreate(
                    [
                        'media_id' => $media->getKey(),
                        'kind' => $kind->value,
                        'variant' => $variant,
                        'format' => $format,
                    ],
                    [
                        'workspace_id' => $workspaceId,
                        'mime_type' => $mimeType,
                        'disk' => $disk,
                        'path' => $path,
                        'size' => $newSize,
                        'width' => $width,
                        'height' => $height,
                        'sha256' => $sha256,
                        'is_primary' => $primary,
                        'generated_at' => now(),
                        'metadata' => $metadata,
                    ],
                );
            });
        } catch (Throwable $exception) {
            if (! $candidateAlreadyExists) {
                $this->orphans->deleteOrTrack($disk, $path, $workspaceId, $newSize, 'derivative_db_rollback');
            }
            if ($workspace && $delta > 0) {
                $this->storage->decreaseUsage($workspace, $delta);
            }
            throw $exception;
        }

        if ($workspace && $delta < 0) {
            $this->storage->decreaseUsage($workspace, abs($delta));
        }

        // Retire the previous physical object only after the new metadata is
        // authoritative. Same-content updates keep the content-addressed path.
        if ($oldDisk instanceof Disk && is_string($oldPath) && $oldPath !== ''
            && ($oldDisk !== $disk || $oldPath !== $path)) {
            $this->orphans->deleteOrTrack(
                $oldDisk,
                $oldPath,
                $workspaceId,
                $oldSize,
                'derivative_replaced',
            );
        }

        return $derivative;
    }

    public function makePrimary(MediaDerivative $derivative): MediaDerivative
    {
        return DB::transaction(function () use ($derivative): MediaDerivative {
            /** @var MediaDerivative $locked */
            $locked = MediaDerivative::query()->findOrFail($derivative->getKey());
            Media::query()->lockForUpdate()->findOrFail($locked->media_id);

            MediaDerivative::query()
                ->where('media_id', $locked->media_id)
                ->where('kind', $locked->kind->value)
                ->update(['is_primary' => false]);
            $locked->forceFill(['is_primary' => true])->save();

            return $locked->refresh();
        });
    }

    /**
     * Delete every derivative and release its workspace quota. Physical cleanup
     * is retriable through the orphan registry.
     */
    public function purge(Media $media): int
    {
        $derivatives = $media->derivatives()->get();
        if ($derivatives->isEmpty()) {
            return 0;
        }

        $usageByWorkspace = [];
        foreach ($derivatives as $derivative) {
            if ($derivative->workspace_id) {
                $workspaceId = (int) $derivative->workspace_id;
                $usageByWorkspace[$workspaceId] = ($usageByWorkspace[$workspaceId] ?? 0) + (int) $derivative->size;
            }
        }

        DB::transaction(function () use ($derivatives, $usageByWorkspace): void {
            $derivatives->each->delete();
            foreach ($usageByWorkspace as $workspaceId => $bytes) {
                $workspace = StorageConfig::findWorkspace($workspaceId);
                if ($workspace && $bytes > 0) {
                    $this->storage->decreaseUsage($workspace, $bytes);
                }
            }
        });

        foreach ($derivatives as $derivative) {
            if ($derivative->disk instanceof Disk && $derivative->path !== '') {
                $this->orphans->deleteOrTrack(
                    $derivative->disk,
                    $derivative->path,
                    $derivative->workspace_id ? (int) $derivative->workspace_id : null,
                    (int) $derivative->size,
                    'derivative_delete',
                );
            }
        }

        return $derivatives->count();
    }

    public function path(
        Media $media,
        MediaDerivativeKind $kind,
        string $variant,
        string $format,
        ?string $sha256 = null,
    ): string {
        $scope = $media->workspace_id ? 'workspace-'.$media->workspace_id : 'global';
        $uuid = $media->uuid ?: (string) $media->getKey();
        $variant = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($variant)) ?: 'default';
        $extension = match ($this->normalizeFormat($format)) {
            'jpeg' => 'jpg',
            default => $this->normalizeFormat($format),
        };
        $fingerprint = is_string($sha256) && $sha256 !== '' ? '-'.substr($sha256, 0, 16) : '';

        return sprintf(
            '.derivatives/%s/%s/%s-%s%s.%s',
            $scope,
            $uuid,
            $kind->value,
            $variant,
            $fingerprint,
            $extension,
        );
    }

    private function normalizeFormat(string $format): string
    {
        $format = strtolower(trim($format));

        return $format === 'jpg' ? 'jpeg' : $format;
    }
}
