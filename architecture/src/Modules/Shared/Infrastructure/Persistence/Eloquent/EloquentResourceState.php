<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Shared\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Tetranyble\Storage\Modules\Shared\Application\Contracts\ResourceState;

final class EloquentResourceState implements ResourceState
{
    public function key(object $resource): int|string|null
    {
        $key = $this->model($resource)->getKey();

        if ($key === null || is_int($key) || is_string($key)) {
            return $key;
        }

        throw new InvalidArgumentException('Eloquent storage resource keys must be integer, string or null.');
    }

    public function attribute(object $resource, string $name, mixed $default = null): mixed
    {
        return $this->model($resource)->getAttribute($name) ?? $default;
    }

    public function morphClass(object $resource): string
    {
        return $this->model($resource)->getMorphClass();
    }

    public function update(object $resource, array $attributes): object
    {
        $model = $this->model($resource);
        $model->forceFill($attributes)->save();

        return $model->refresh();
    }

    public function refresh(object $resource): object
    {
        return $this->model($resource)->refresh();
    }

    public function delete(object $resource): void
    {
        $this->model($resource)->delete();
    }

    private function model(object $resource): Model
    {
        if (! $resource instanceof Model) {
            throw new InvalidArgumentException('Expected an Eloquent-backed storage resource.');
        }

        return $resource;
    }
}
