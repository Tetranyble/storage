<?php

namespace Tetranyble\Storage\Modules\Versioning\Infrastructure\Application;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;

/**
 * Serializes selection of the current media item for a model/purpose pair.
 */
class CurrentMediaSelectionService
{
    public function select(Media $media): Media
    {
        $model = $media->mediable;
        if (! $model instanceof Model) {
            throw new RuntimeException('Standalone media cannot be selected as a model default.');
        }

        return DB::transaction(function () use ($model, $media): Media {
            $this->clearOthers($model, $media->use, $media->getKey());
            $media->forceFill(['current' => true])->save();

            return $media->refresh();
        });
    }

    public function clearOthers(
        Model $model,
        MediaPurpose $purpose,
        int|string|null $exceptMediaId = null,
    ): void {
        $query = Media::query()
            ->where('mediable_type', $model->getMorphClass())
            ->where('mediable_id', $model->getKey())
            ->where('use', $purpose)
            ->where('current', true);

        if ($exceptMediaId !== null) {
            $query->whereKeyNot($exceptMediaId);
        }

        $ids = $query->lockForUpdate()->pluck($query->getModel()->getQualifiedKeyName());
        if ($ids->isNotEmpty()) {
            Media::query()->whereKey($ids)->update(['current' => false]);
        }
    }
}
