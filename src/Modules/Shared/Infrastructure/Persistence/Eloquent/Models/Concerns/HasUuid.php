<?php

namespace Tetranyble\Storage\Modules\Shared\Infrastructure\Persistence\Eloquent\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUniqueIds;
use Illuminate\Support\Str;

trait HasUuid
{
    use HasUniqueIds;

    public function usesUniqueIds(): bool
    {
        return true;
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }
}
