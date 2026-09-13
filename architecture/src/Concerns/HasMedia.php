<?php

namespace Tetranyble\Storage\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaStatus;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;

trait HasMedia
{
    public function medium(): MorphOne
    {
        return $this->morphOne($this->mediaModelClass(), 'mediable')->latestOfMany();
    }

    public function media(): MorphMany
    {
        return $this->morphMany($this->mediaModelClass(), 'mediable');
    }

    public function images(): MorphMany
    {
        return $this->media()->where('mime_type', 'like', 'image/%');
    }

    public function videos(): MorphMany
    {
        return $this->media()->where('mime_type', 'like', 'video/%');
    }

    protected function mediaModelClass(): string
    {
        return Media::class;
    }

    public function currentMedia(?MediaPurpose $purpose = null): ?Media
    {
        return $this->media()
            ->where('current', true)
            ->when($purpose, fn ($query) => $query->where('use', $purpose))
            ->latest()
            ->first();
    }

    public function mediaForPurpose(MediaPurpose $purpose, bool $currentOnly = true)
    {
        return $this->media()
            ->where('use', $purpose)
            ->when($currentOnly, fn ($query) => $query->where('current', true))
            ->latest()
            ->get();
    }

    public function findMedia(string|int $key, bool $withTrashed = false): ?Media
    {
        $query = $this->media();
        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query
            ->where(fn ($builder) => $builder->whereKey($key)->orWhere('uuid', $key))
            ->first();
    }

    public function default(string $type = 'image'): Model
    {
        $defaults = config('tetranyble-storage.defaults.'.($type === 'video' ? 'video' : 'image'), []);
        $model = $this->mediaModelClass();

        return new $model([
            'path' => $defaults['path'] ?? null,
            'disk' => $defaults['disk'] ?? Disk::PUBLIC->value,
        ]);
    }

    public function getImageAttribute(): Media|Model
    {
        return $this->images()->where('current', true)->latest()->first() ?? $this->default();
    }

    public function getVideoAttribute(): Media|Model
    {
        return $this->videos()->where('current', true)->latest()->first() ?? $this->default('video');
    }

    public function getFaviconAttribute(): ?string
    {
        return $this->currentMedia(MediaPurpose::FAVICON)?->url
            ?? config('tetranyble-storage.defaults.image.path');
    }

    public function getLogoAttribute(): ?string
    {
        return $this->currentMedia(MediaPurpose::LOGO)?->url
            ?? config('tetranyble-storage.defaults.image.path');
    }

    public function getProfileAttribute(): Media|Model
    {
        return $this->currentMedia(MediaPurpose::PROFILE) ?? $this->default();
    }

    public function workspaceLogo(): ?string
    {
        return $this->media()
            ->where('current', true)
            ->where('use', MediaPurpose::LOGO)
            ->where('status', MediaStatus::READY)
            ->latest()
            ->first()?->url;
    }
}
