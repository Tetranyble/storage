<?php

namespace Tetranyble\Storage\Tests\Unit\CloudDrive;

use Tetranyble\Storage\Domain\CloudDrive\ConnectedDriveService;
use Tetranyble\Storage\Domain\CloudDrive\OAuthService;
use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\StorageService;
use Tetranyble\Storage\Enums\CloudProvider;
use Tetranyble\Storage\Enums\ConnectedDriveStatus;
use Tetranyble\Storage\Models\ConnectedDrive;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Models\User;
use Tetranyble\Storage\Tests\PackageTestCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Mockery;

class ConnectedDriveDefaultTest extends PackageTestCase
{
    private ConnectedDriveService $service;
    private Workspace $workspace;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $oauth   = Mockery::mock(OAuthService::class);
        $files   = Mockery::mock(FileSystemContract::class);
        $storage = Mockery::mock(StorageService::class);

        $this->service = new ConnectedDriveService($oauth, $files, $storage);

        $this->workspace = Workspace::create(['name' => 'Acme', 'uuid' => Str::uuid()]);
        $this->user   = User::create(['name' => 'Alice', 'uuid' => Str::uuid(), 'workspace_id' => $this->workspace->id]);

        Event::fake();
    }

    public function test_first_connected_drive_is_auto_default(): void
    {
        $drive = $this->connectOAuth('GDrive #1');

        $this->assertTrue((bool) $drive->is_default);
    }

    public function test_second_drive_is_not_auto_default(): void
    {
        $this->connectOAuth('GDrive #1');
        $second = $this->connectOAuth('GDrive #2');

        $this->assertFalse((bool) $second->is_default);
    }

    public function test_only_one_default_per_workspace(): void
    {
        $this->connectOAuth('Drive A');
        $this->connectOAuth('Drive B');
        $this->connectOAuth('Drive C');

        $defaults = ConnectedDrive::where('workspace_id', $this->workspace->id)
            ->where('is_default', true)
            ->count();

        $this->assertSame(1, $defaults);
    }

    public function test_database_rejects_two_default_drives_for_one_workspace(): void
    {
        $this->connectOAuth('Drive A');

        $this->expectException(QueryException::class);
        ConnectedDrive::create([
            'uuid' => Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'provider' => CloudProvider::GOOGLE_DRIVE,
            'name' => 'Drive B',
            'status' => ConnectedDriveStatus::CONNECTED,
            'is_default' => true,
            'default_slot' => 'default',
        ]);
    }

    public function test_set_default_swaps_atomically(): void
    {
        $first  = $this->connectOAuth('First');
        $second = $this->connectOAuth('Second');

        $this->service->setDefault($this->workspace, $second);

        $first->refresh();
        $second->refresh();

        $this->assertFalse((bool) $first->is_default);
        $this->assertTrue((bool) $second->is_default);
    }

    public function test_set_default_rejects_wrong_workspace(): void
    {
        $other = Workspace::create(['name' => 'Other', 'uuid' => Str::uuid()]);
        $drive = ConnectedDrive::create([
            'uuid'      => Str::uuid(),
            'workspace_id' => $other->id,
            'provider'  => CloudProvider::GOOGLE_DRIVE,
            'name'      => 'Foreign',
            'status'    => ConnectedDriveStatus::CONNECTED,
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->setDefault($this->workspace, $drive);
    }

    public function test_get_default_returns_default_drive(): void
    {
        $first  = $this->connectOAuth('First');
        $second = $this->connectOAuth('Second');

        $this->service->setDefault($this->workspace, $second);

        $default = $this->service->getDefault($this->workspace);

        $this->assertNotNull($default);
        $this->assertSame($second->id, $default->id);
    }

    public function test_get_default_returns_null_when_no_drives(): void
    {
        $default = $this->service->getDefault($this->workspace);

        $this->assertNull($default);
    }

    public function test_disconnecting_default_promotes_next(): void
    {
        $first  = $this->connectOAuth('First');   // becomes default
        $second = $this->connectOAuth('Second');  // not default

        $this->service->disconnect($this->workspace, $first, $this->user);

        $default = $this->service->getDefault($this->workspace);

        $this->assertNotNull($default, 'A new default should be promoted');
        $this->assertSame($second->id, $default->id);
    }

    public function test_disconnecting_non_default_does_not_change_default(): void
    {
        $first  = $this->connectOAuth('First');   // default
        $second = $this->connectOAuth('Second');  // not default

        $this->service->disconnect($this->workspace, $second, $this->user);

        $default = $this->service->getDefault($this->workspace);

        $this->assertNotNull($default);
        $this->assertSame($first->id, $default->id);
    }

    public function test_disconnecting_last_drive_leaves_no_default(): void
    {
        $only = $this->connectOAuth('Only');

        $this->service->disconnect($this->workspace, $only, $this->user);

        $this->assertNull($this->service->getDefault($this->workspace));
    }

    public function test_resolve_drive_uses_explicit_drive(): void
    {
        $first  = $this->connectOAuth('First');
        $second = $this->connectOAuth('Second');
        // default is $first; we pass $second explicitly
        $resolved = $this->service->resolveDrive($this->workspace, $second);

        $this->assertSame($second->id, $resolved->id);
    }

    public function test_resolve_drive_falls_back_to_default(): void
    {
        $first = $this->connectOAuth('First');

        $resolved = $this->service->resolveDrive($this->workspace);

        $this->assertSame($first->id, $resolved->id);
    }

    public function test_resolve_drive_throws_when_no_default_and_no_drive_given(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No default drive/i');

        $this->service->resolveDrive($this->workspace);
    }

    public function test_list_connected_shows_default_first(): void
    {
        $first  = $this->connectOAuth('Alpha');
        $second = $this->connectOAuth('Zeta');

        $this->service->setDefault($this->workspace, $second);

        $list = $this->service->listConnected($this->workspace);

        $this->assertSame($second->id, $list->first()->id, 'Default drive should appear first');
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function connectOAuth(string $name): ConnectedDrive
    {
        return $this->service->connectOAuth($this->workspace, CloudProvider::GOOGLE_DRIVE, [
            'access_token'  => 'tok-'.Str::random(8),
            'refresh_token' => 'ref-'.Str::random(8),
            'expires_at'    => Carbon::now()->addHour(),
        ], $name);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
