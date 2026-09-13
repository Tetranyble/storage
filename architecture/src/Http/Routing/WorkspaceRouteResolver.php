<?php

namespace Tetranyble\Storage\Http\Routing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;
use Tetranyble\Storage\Support\StorageConfig;

final class WorkspaceRouteResolver
{
    /** @template TModel of Model
     * @param  class-string<TModel>  $modelClass
     * @return TModel
     */
    public function resolve(string $modelClass, Model $workspace, string|int $key, bool $withTrashed = false): Model
    {
        /** @var TModel $prototype */
        $prototype = new $modelClass;
        $query = $modelClass::query()->where(StorageConfig::resourceWorkspaceForeignKey(), $workspace->getKey());

        if ($withTrashed && in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        $resource = $query->where(function ($builder) use ($prototype, $key): void {
            $builder->where($prototype->getRouteKeyName(), $key);
            if ($prototype->getRouteKeyName() !== $prototype->getKeyName()) {
                $builder->orWhere($prototype->getKeyName(), $key);
            }
            if ($prototype->getRouteKeyName() !== 'uuid') {
                $builder->orWhere('uuid', $key);
            }
        })->first();

        if (! $resource instanceof Model) {
            throw new ResourceNotFoundException;
        }

        return $resource;
    }
}
