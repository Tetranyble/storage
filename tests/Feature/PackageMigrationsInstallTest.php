<?php

namespace Tetranyble\Storage\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Tetranyble\Storage\StorageServiceProvider;

class PackageMigrationsInstallTest extends TestCase
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
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('tetranyble-storage.routes.enabled', false);
        $app['config']->set('tetranyble-storage.activities.enabled', false);
        $app['config']->set('tetranyble-storage.activities.load_migrations', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }

    public function test_core_package_migrations_can_run_without_a_workspaces_table(): void
    {
        foreach ($this->migrationFiles(__DIR__.'/../../database/migrations') as $path) {
            (require $path)->up();
        }

        $this->assertFalse(Schema::hasTable('workspaces'));
        $this->assertTrue(Schema::hasTable('folders'));
        $this->assertTrue(Schema::hasTable('media'));
        $this->assertTrue(Schema::hasTable('media_shares'));
        $this->assertTrue(Schema::hasTable('collaborator_grants'));
        $this->assertTrue(Schema::hasTable('resource_stars'));
        $this->assertTrue(Schema::hasTable('upload_sessions'));
        $this->assertTrue(Schema::hasTable('upload_session_chunks'));
        $this->assertTrue(Schema::hasTable('storage_comments'));
        $this->assertTrue(Schema::hasTable('connected_drives'));
    }

    public function test_optional_activity_migration_can_run_without_a_workspaces_table(): void
    {
        (require __DIR__.'/../../database/migrations/activities/2026_06_06_000005_create_activities_table.php')->up();

        $this->assertFalse(Schema::hasTable('workspaces'));
        $this->assertTrue(Schema::hasTable('activities'));
    }

    /**
     * @return array<int, string>
     */
    private function migrationFiles(string $directory): array
    {
        $files = glob($directory.'/*_*.php') ?: [];
        sort($files);

        return array_values(array_filter(
            $files,
            fn (string $path): bool => ! str_contains($path, '/activities/')
        ));
    }
}
