<?php

namespace Tetranyble\Storage\Http\Routing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
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

        $routeKey = $prototype->getRouteKeyName();
        $primaryKey = $prototype->getKeyName();
        $isIntegerKey = is_int($key) || (is_string($key) && ctype_digit($key));
        $isUuid = is_string($key) && Str::isUuid($key);

        if ($routeKey === $primaryKey && ! $isIntegerKey && ! $isUuid) {
            throw new ResourceNotFoundException;
        }

        $resource = $query->where(function ($builder) use ($routeKey, $primaryKey, $key, $isIntegerKey, $isUuid): void {
            if ($routeKey !== $primaryKey && $routeKey !== 'uuid') {
                $builder->where($routeKey, $key);
            }
            if ($isIntegerKey) {
                $builder->orWhere($primaryKey, $key);
            }
            if ($isUuid) {
                $builder->orWhere('uuid', $key);
            }
        })->first();

        if (! $resource instanceof Model) {
            throw new ResourceNotFoundException;
        }

        return $resource;
    }
}
