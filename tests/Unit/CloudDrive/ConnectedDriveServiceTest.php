<?php

namespace Tetranyble\Storage\Tests\Unit\CloudDrive;

use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;
use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\InvalidStorageOperationException;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\ConnectedDriveService;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Contracts\CloudAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Domain\DTO\CloudFile;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\OAuthService;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageLifecycleService;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageService;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\ConnectedDriveStatus;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\User;
use Tetranyble\Storage\Tests\PackageTestCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;

class ConnectedDriveServiceTest extends PackageTestCase
{
    private MockInterface $oauth;
    private MockInterface $files;
    private MockInterface $storage;
    private ConnectedDriveService $service;
    private Workspace $workspace;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oauth   = Mockery::mock(OAuthService::class);
        $this->files   = Mockery::mock(FileSystemContract::class);
        $this->storage = Mockery::mock(StorageService::class);

        $this->service = new ConnectedDriveService($this->oauth, $this->files, $this->storage);

        $this->workspace = Workspace::create(['name' => 'Acme Corp', 'uuid' => \Illuminate\Support\Str::uuid()]);
        $this->user   = User::create(['name' => 'Alice', 'uuid' => \Illuminate\Support\Str::uuid(), 'workspace_id' => $this->workspace->id]);

        Event::fake();
    }

    public function test_connect_oauth_creates_connected_drive(): void
    {
        $tokenData = [
            'access_token'  => 'goog-token',
            'refresh_token' => 'goog-refresh',
            'expires_at'    => Carbon::now()->addHour(),
        ];

        $drive = $this->service->connectOAuth($this->workspace, CloudProvider::GOOGLE_DRIVE, $tokenData, 'My GDrive');

        $this->assertInstanceOf(ConnectedDrive::class, $drive);
        $this->assertSame(CloudProvider::GOOGLE_DRIVE, $drive->provider);
        $this->assertSame('My GDrive', $drive->name);
        $this->assertSame(ConnectedDriveStatus::CONNECTED, $drive->status);
        $this->assertDatabaseHas('connected_drives', ['workspace_id' => $this->workspace->id, 'name' => 'My GDrive']);
    }

    public function test_connect_oauth_throws_for_s3(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->connectOAuth($this->workspace, CloudProvider::S3, [], 'My S3');
    }

    public function test_disconnect_soft_deletes_drive(): void
    {
        $drive = $this->makeDrive(CloudProvider::GOOGLE_DRIVE);

        $this->service->disconnect($this->workspace, $drive, $this->user);

        $this->assertSoftDeleted('connected_drives', ['id' => $drive->id]);
    }

    public function test_disconnect_fires_event(): void
    {
        $drive = $this->makeDrive(CloudProvider::GOOGLE_DRIVE);

        $this->service->disconnect($this->workspace, $drive, $this->user);

        Event::assertDispatched(\Tetranyble\Storage\Events\DriveDisconnected::class);
    }

    public function test_disconnect_aborts_for_wrong_workspace(): void
    {
        $other = Workspace::create(['name' => 'Other', 'uuid' => \Illuminate\Support\Str::uuid()]);
        $drive = ConnectedDrive::create([
            'uuid'      => \Illuminate\Support\Str::uuid(),
            'workspace_id' => $other->id,
            'provider'  => CloudProvider::GOOGLE_DRIVE,
            'name'      => 'Drive',
            'status'    => ConnectedDriveStatus::CONNECTED,
        ]);

        $this->expectException(ResourceNotFoundException::class);

        $this->service->disconnect($this->workspace, $drive, $this->user);
    }

    public function test_list_connected_returns_only_workspace_drives(): void
    {
        $this->makeDrive(CloudProvider::GOOGLE_DRIVE, 'GDrive');
        $this->makeDrive(CloudProvider::ONEDRIVE, 'OneDrive');

        $other = Workspace::create(['name' => 'Other', 'uuid' => \Illuminate\Support\Str::uuid()]);
        ConnectedDrive::create(['uuid' => \Illuminate\Support\Str::uuid(), 'workspace_id' => $other->id, 'provider' => CloudProvider::S3, 'name' => 'Other S3', 'status' => ConnectedDriveStatus::CONNECTED]);

        $list = $this->service->listConnected($this->workspace);

        $this->assertCount(2, $list);
        $this->assertTrue($list->every(fn ($d) => (int) $d->workspace_id === (int) $this->workspace->id));
    }

    public function test_browse_folder_returns_structured_response(): void
    {
        $drive   = $this->makeDrive(CloudProvider::GOOGLE_DRIVE);
        $adapter = Mockery::mock(CloudAdapter::class);
        $cloudFile = new CloudFile('f1', 'report.pdf', false, 1024, 'application/pdf', null, null, null);
        $adapter->shouldReceive('listFolder')->with('root')->andReturn([$cloudFile]);

        // Inject a pre-built adapter into the service (bypass adapterFor factory)
        $service = Mockery::mock(ConnectedDriveService::class, [$this->oauth, $this->files, $this->storage])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('adapterFor')->andReturn($adapter);

        $result = $service->browseFolder($this->workspace, $drive, 'root');

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('drive', $result);
        $this->assertCount(1, $result['items']);
        $this->assertSame('report.pdf', $result['items'][0]['name']);
    }

    public function test_provider_browse_outage_does_not_mutate_drive_or_workspace_state(): void
    {
        $drive = $this->makeDrive(CloudProvider::GOOGLE_DRIVE);
        $beforeUsage = (int) $this->workspace->storage_used_bytes;
        $adapter = Mockery::mock(CloudAdapter::class);
        $adapter->shouldReceive('listFolder')->once()->with('root')->andThrow(new RuntimeException('provider unavailable'));

        $service = Mockery::mock(ConnectedDriveService::class, [$this->oauth, $this->files, $this->storage])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('adapterFor')->andReturn($adapter);

        try {
            $service->browseFolder($this->workspace, $drive, 'root');
            $this->fail('Provider outage should escape without committing local state.');
        } catch (RuntimeException $exception) {
            $this->assertSame('provider unavailable', $exception->getMessage());
        }

        $this->assertSame($beforeUsage, (int) $this->workspace->fresh()->storage_used_bytes);
        $this->assertSame(ConnectedDriveStatus::CONNECTED, $drive->fresh()->status);
        $this->assertNull($drive->fresh()->last_error);
    }

    public function test_import_file_uses_compensated_storage_lifecycle(): void
    {
        Storage::fake('local');
        config()->set('tetranyble-storage.default_disk', 'local');

        $workspace = $this->workspace->forceFill([
            'storage_quota_bytes' => 1024,
            'storage_used_bytes' => 0,
        ]);
        $workspace->save();
        $folder = Folder::create([
            'workspace_id' => $workspace->id,
            'name' => 'Imports',
            'slug' => 'imports',
            'path' => 'imports',
            'uuid' => \Illuminate\Support\Str::uuid(),
        ]);
        $drive = $this->makeDrive(CloudProvider::LOCAL);

        $adapter = Mockery::mock(CloudAdapter::class);
        $adapter->shouldReceive('getMetadata')->once()->with('remote-file')->andReturn(
            new CloudFile('remote-file', 'note.txt', false, 5, 'text/plain', null, null, null),
        );
        $adapter->shouldReceive('getFileBinary')->once()->with('remote-file')->andReturn('hello');

        $files = $this->app->make(FileSystemContract::class);
        $storage = $this->app->make(StorageService::class);
        $lifecycle = $this->app->make(StorageLifecycleService::class);
        $service = Mockery::mock(ConnectedDriveService::class, [
            $this->oauth, $files, $storage, null, null, $lifecycle,
        ])->makePartial();
        $service->shouldReceive('adapterFor')->andReturn($adapter);

        $media = $service->importFile($workspace, $drive, 'remote-file', $folder, $this->user);

        $this->assertSame(5, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertSame(5, (int) $media->size);
        Storage::disk('local')->assertExists($media->path);
    }

    public function test_import_file_rejects_oversized_provider_metadata_before_downloading_binary(): void
    {
        config()->set('tetranyble-storage.uploads.max_size', 4);

        $folder = Folder::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Imports',
            'slug' => 'imports',
            'path' => 'imports',
            'uuid' => \Illuminate\Support\Str::uuid(),
        ]);
        $drive = $this->makeDrive(CloudProvider::LOCAL);

        $adapter = Mockery::mock(CloudAdapter::class);
        $adapter->shouldReceive('getMetadata')->once()->with('too-large')->andReturn(
            new CloudFile('too-large', 'large.bin', false, 5, 'application/octet-stream', null, null, null),
        );
        $adapter->shouldNotReceive('getFileBinary');

        $service = Mockery::mock(ConnectedDriveService::class, [
            $this->oauth, $this->files, $this->storage,
        ])->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('adapterFor')->andReturn($adapter);

        $this->expectException(InvalidStorageOperationException::class);
        $this->expectExceptionMessage('configured maximum size');

        $service->importFile($this->workspace, $drive, 'too-large', $folder, $this->user);
    }

    public function test_import_file_rechecks_actual_binary_size_when_provider_metadata_is_unknown(): void
    {
        Storage::fake('local');
        config()->set('tetranyble-storage.default_disk', 'local');
        config()->set('tetranyble-storage.uploads.max_size', 4);

        $folder = Folder::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Imports',
            'slug' => 'imports',
            'path' => 'imports',
            'uuid' => \Illuminate\Support\Str::uuid(),
        ]);
        $drive = $this->makeDrive(CloudProvider::LOCAL);

        $adapter = Mockery::mock(CloudAdapter::class);
        $adapter->shouldReceive('getMetadata')->once()->with('unknown-size')->andReturn(
            new CloudFile('unknown-size', 'large.bin', false, null, 'application/octet-stream', null, null, null),
        );
        $adapter->shouldReceive('getFileBinary')->once()->with('unknown-size')->andReturn('12345');

        $service = Mockery::mock(ConnectedDriveService::class, [
            $this->oauth, $this->files, $this->storage,
        ])->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('adapterFor')->andReturn($adapter);

        try {
            $service->importFile($this->workspace, $drive, 'unknown-size', $folder, $this->user);
            $this->fail('Actual cloud-drive binary size should be checked before persistence.');
        } catch (InvalidStorageOperationException $exception) {
            $this->assertStringContainsString('configured maximum size', $exception->getMessage());
        }

        $this->assertSame(0, Media::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_import_file_compensates_object_and_quota_when_media_persistence_fails(): void
    {
        Storage::fake('local');
        config()->set('tetranyble-storage.default_disk', 'local');

        $workspace = $this->workspace->forceFill([
            'storage_quota_bytes' => 1024,
            'storage_used_bytes' => 0,
        ]);
        $workspace->save();
        $folder = Folder::create([
            'workspace_id' => $workspace->id,
            'name' => 'Imports',
            'slug' => 'imports',
            'path' => 'imports',
            'uuid' => \Illuminate\Support\Str::uuid(),
        ]);
        $drive = $this->makeDrive(CloudProvider::LOCAL);

        $adapter = Mockery::mock(CloudAdapter::class);
        $adapter->shouldReceive('getMetadata')->andReturn(
            new CloudFile('remote-file', 'note.txt', false, 5, 'text/plain', null, null, null),
        );
        $adapter->shouldReceive('getFileBinary')->andReturn('hello');

        $files = $this->app->make(FileSystemContract::class);
        $storage = $this->app->make(StorageService::class);
        $lifecycle = $this->app->make(StorageLifecycleService::class);
        $service = Mockery::mock(ConnectedDriveService::class, [
            $this->oauth, $files, $storage, null, null, $lifecycle,
        ])->makePartial();
        $service->shouldReceive('adapterFor')->andReturn($adapter);

        $event = 'eloquent.creating: '.Media::class;
        $dispatcher = Media::getEventDispatcher();
        $dispatcher?->listen($event, static function (): never {
            throw new RuntimeException('forced cloud import persistence failure');
        });

        try {
            $service->importFile($workspace, $drive, 'remote-file', $folder, $this->user);
            $this->fail('The forced persistence failure should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced cloud import persistence failure', $exception->getMessage());
        } finally {
            $dispatcher?->forget($event);
        }

        $this->assertSame(0, Media::query()->count());
        $this->assertSame(0, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_export_file_calls_put_file_on_adapter(): void
    {
        $drive  = $this->makeDrive(CloudProvider::S3);
        $folder = Folder::create([
            'workspace_id' => $this->workspace->id,
            'name'      => 'Root',
            'slug'      => 'root',
            'path'      => '/',
            'uuid'      => \Illuminate\Support\Str::uuid(),
        ]);
        $media = Media::create([
            'workspace_id'     => $this->workspace->id,
            'folder_id'     => $folder->id,
            'uuid'          => \Illuminate\Support\Str::uuid(),
            'disk'          => Disk::PUBLIC,
            'path'          => 'test/file.pdf',
            'original_name' => 'report.pdf',
            'mime_type'     => 'application/pdf',
        ]);

        $adapter   = Mockery::mock(CloudAdapter::class);
        $cloudFile = new CloudFile('remote-id', 'report.pdf', false, 100, 'application/pdf', null, null, null);
        $adapter->shouldReceive('putFile')->once()->andReturn($cloudFile);

        $this->files->shouldReceive('get')->once()->andReturn('pdf binary content');

        $service = Mockery::mock(ConnectedDriveService::class, [$this->oauth, $this->files, $this->storage])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('adapterFor')->andReturn($adapter);

        $result = $service->exportFile($this->workspace, $media, $drive, 'root');

        $this->assertSame('remote-id', $result->id);
    }

    // ---------------------------------------------------------------
    // Local drive
    // ---------------------------------------------------------------

    public function test_connect_local_creates_drive_with_disk_name(): void
    {
        $drive = $this->service->connectLocal($this->workspace, 'local', 'My Local Disk');

        $this->assertInstanceOf(ConnectedDrive::class, $drive);
        $this->assertSame(CloudProvider::LOCAL, $drive->provider);
        $this->assertSame('My Local Disk', $drive->name);
        $this->assertSame('local', $drive->credentials['disk']);
        $this->assertSame(ConnectedDriveStatus::CONNECTED, $drive->status);
        $this->assertTrue((bool) $drive->is_default);
    }

    public function test_connect_local_public_disk_is_supported(): void
    {
        $drive = $this->service->connectLocal($this->workspace, 'public', 'Public Files');

        $this->assertSame('public', $drive->credentials['disk']);
        $this->assertSame(CloudProvider::LOCAL, $drive->provider);
    }

    public function test_connect_local_second_drive_is_not_default(): void
    {
        $this->service->connectLocal($this->workspace, 'local', 'Private');
        $second = $this->service->connectLocal($this->workspace, 'public', 'Public');

        $this->assertFalse((bool) $second->is_default);
    }

    public function test_connect_local_fires_drive_connected_event(): void
    {
        $this->service->connectLocal($this->workspace, 'local', 'My Local');

        Event::assertDispatched(\Tetranyble\Storage\Events\DriveConnected::class);
    }

    public function test_connect_oauth_throws_for_local(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->connectOAuth($this->workspace, CloudProvider::LOCAL, [], 'Local');
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeDrive(CloudProvider $provider, string $name = 'Test Drive'): ConnectedDrive
    {
        return ConnectedDrive::create([
            'uuid'         => \Illuminate\Support\Str::uuid(),
            'workspace_id'    => $this->workspace->id,
            'provider'     => $provider,
            'name'         => $name,
            'access_token' => 'fake-token',
            'status'       => ConnectedDriveStatus::CONNECTED,
            'connected_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
