<?php

namespace Tetranyble\Storage\Tests\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class QueryPlanInspector
{
    /** @return array<int,mixed> */
    public function explain(Builder $builder): array
    {
        $connection = $builder->getModel()->getConnection();
        $driver = $connection->getDriverName();
        $sql = $builder->toSql();
        $bindings = $builder->getBindings();

        $prefix = match ($driver) {
            'pgsql' => 'EXPLAIN (FORMAT JSON) ',
            'mysql', 'mariadb' => 'EXPLAIN FORMAT=JSON ',
            default => 'EXPLAIN QUERY PLAN ',
        };

        return DB::connection($connection->getName())->select($prefix.$sql, $bindings);
    }
}
