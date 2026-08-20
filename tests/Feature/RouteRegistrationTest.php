<?php

namespace Tetranyble\Storage\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Tetranyble\Storage\Domain\FileSystem\Contracts\MediaUploader;
use Tetranyble\Storage\StorageServiceProvider;

class RouteRegistrationTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function getPackageProviders($app): array
    {
        return [StorageServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('tetranyble-storage.routes.enabled', false);
    }

    public function test_all_package_routes_can_be_disabled_without_disabling_services(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('tetranyble-storage.media.show'));
        $this->assertNull(Route::getRoutes()->getByName('tetranyble-storage.shares.download'));
        $this->assertTrue($this->app->bound(MediaUploader::class));
    }

    public function test_published_route_file_honours_the_disabled_setting(): void
    {
        require __DIR__.'/../../routes/storage.php';

        $this->assertNull(Route::getRoutes()->getByName('tetranyble-storage.media.show'));
        $this->assertNull(Route::getRoutes()->getByName('tetranyble-storage.shares.download'));
    }
}
