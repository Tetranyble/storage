<?php

namespace Tetranyble\Storage\Http\Routing;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tetranyble\Storage\Modules\Sharing\Application\Contracts\ShareUrlGenerator;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Persistence\Eloquent\Models\MediaShare;

class LaravelShareUrlGenerator implements ShareUrlGenerator
{
    public function urlFor(object $share, bool $absolute = true): string
    {
        if (! $share instanceof MediaShare) {
            throw new \InvalidArgumentException('Share URL generation requires a MediaShare model.');
        }
        $routeName = (string) config('tetranyble-storage.routes.name', 'tetranyble-storage.').'shares.download';

        if (! Route::has($routeName)) {
            throw new RuntimeException("The [{$routeName}] route is not registered. Enable package routes to generate share URLs.");
        }

        return route($routeName, ['token' => $share->token], $absolute);
    }
}
