<?php

namespace Tetranyble\Storage\Domain\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Models\Media;

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
        $query = $model->media()
            ->where('use', $purpose)
            ->where('current', true);

        if ($exceptMediaId !== null) {
            $query->whereKeyNot($exceptMediaId);
        }

        $ids = $query->lockForUpdate()->pluck($query->getModel()->getQualifiedKeyName());
        if ($ids->isNotEmpty()) {
            $model->media()->whereKey($ids)->update(['current' => false]);
        }
    }
}
