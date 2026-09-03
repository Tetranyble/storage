<?php

namespace Tetranyble\Storage\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
        $this->assertTrue(Schema::hasTable('media_version_groups'));
        $this->assertTrue(Schema::hasTable('storage_orphans'));
        $this->assertTrue(Schema::hasTable('media_shares'));
        $this->assertTrue(Schema::hasTable('collaborator_grants'));
        $this->assertTrue(Schema::hasTable('resource_stars'));
        $this->assertTrue(Schema::hasTable('upload_sessions'));
        $this->assertTrue(Schema::hasTable('upload_session_chunks'));
        $this->assertTrue(Schema::hasTable('storage_comments'));
        $this->assertTrue(Schema::hasTable('connected_drives'));
    }

    public function test_version_integrity_migration_rejects_duplicate_version_numbers(): void
    {
        (require __DIR__.'/../../database/migrations/2026_06_06_000001_create_folders_table.php')->up();
        (require __DIR__.'/../../database/migrations/2026_06_06_000002_create_media_table.php')->up();

        $groupUuid = (string) Str::uuid();
        foreach (range(1, 2) as $index) {
            DB::table('media')->insert([
                'uuid' => (string) Str::uuid(),
                'version_group_uuid' => $groupUuid,
                'version_number' => 1,
                'current' => $index === 2,
            ]);
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('contains duplicate version number');

        (require __DIR__.'/../../database/migrations/2026_06_06_000011_harden_media_version_integrity.php')->up();
    }

    public function test_version_integrity_migration_normalizes_multiple_current_rows_and_seeds_allocator(): void
    {
        (require __DIR__.'/../../database/migrations/2026_06_06_000001_create_folders_table.php')->up();
        (require __DIR__.'/../../database/migrations/2026_06_06_000002_create_media_table.php')->up();

        $groupUuid = (string) Str::uuid();
        $firstId = DB::table('media')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'version_group_uuid' => $groupUuid,
            'version_number' => 1,
            'current' => true,
        ]);
        $secondId = DB::table('media')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'version_group_uuid' => $groupUuid,
            'version_number' => 2,
            'current' => true,
        ]);

        (require __DIR__.'/../../database/migrations/2026_06_06_000011_harden_media_version_integrity.php')->up();

        $this->assertSame(0, (int) DB::table('media')->where('id', $firstId)->value('current'));
        $this->assertSame(1, (int) DB::table('media')->where('id', $secondId)->value('current'));
        $this->assertDatabaseHas('media_version_groups', [
            'version_group_uuid' => $groupUuid,
            'next_version_number' => 3,
            'current_media_id' => $secondId,
        ]);
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
