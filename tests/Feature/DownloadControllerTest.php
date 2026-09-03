<?php

namespace Tetranyble\Storage\Tests\Feature;

use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Enums\AccessScope;
use Tetranyble\Storage\Enums\MediaStatus;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Models\User;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;

class DownloadControllerTest extends PackageTestCase
{
    private Workspace $workspace;
    private User   $user;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Point the web guard at the package User model so actingAs() works
        $app['config']->set('auth.guards.web', [
            'driver'   => 'session',
            'provider' => 'package_users',
        ]);
        $app['config']->set('auth.providers.package_users', [
            'driver' => 'eloquent',
            'model'  => User::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->workspace = Workspace::create(['name' => 'Acme', 'uuid' => Str::uuid()]);
        $this->user   = User::create([
            'uuid'      => Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'name'      => 'Alice',
            'email'     => 'alice@example.com',
        ]);
    }

    // ---------------------------------------------------------------
    // Single-file download
    // ---------------------------------------------------------------

    public function test_download_returns_file_content(): void
    {
        Storage::disk('local')->put('files/hello.txt', 'file content here');

        $media = $this->mediaRecord('hello.txt', AccessScope::WORKSPACE);

        $response = $this->actingAs($this->user)
            ->get(route('tetranyble-storage.media.download', $media->uuid));

        $response->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="hello.txt"')
            ->assertSee('file content here');

        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
    }

    public function test_download_requires_authentication(): void
    {
        $media = $this->mediaRecord('hello.txt', AccessScope::WORKSPACE);

        // JSON request returns 401 instead of redirect (no login route in package)
        $this->getJson(route('tetranyble-storage.media.download', $media->uuid))
            ->assertUnauthorized();
    }

    public function test_download_returns_404_for_wrong_workspace(): void
    {
        Storage::disk('local')->put('files/other.txt', 'data');

        $otherWorkspace = Workspace::create(['name' => 'Other', 'uuid' => Str::uuid()]);
        $media = Media::create([
            'uuid'          => Str::uuid(),
            'workspace_id'     => $otherWorkspace->id,
            'disk'          => Disk::PRIVATE->value,
            'path'          => 'files/other.txt',
            'original_name' => 'other.txt',
            'mime_type'     => 'text/plain',
            'access_scope'  => AccessScope::WORKSPACE->value,
            'status'        => MediaStatus::READY->value,
        ]);

        $this->actingAs($this->user)
            ->get(route('tetranyble-storage.media.download', $media->uuid))
            ->assertNotFound();
    }

    public function test_download_restricted_scope_without_permission_returns_403(): void
    {
        Storage::disk('local')->put('files/secret.txt', 'secret');

        $access = Mockery::mock(ResourceAccessControl::class);
        $access->shouldReceive('authorizeView')->once()->andThrow(
            new \Symfony\Component\HttpKernel\Exception\HttpException(403, 'Forbidden')
        );
        $access->shouldReceive('canView')->andReturn(false);
        $this->app->instance(ResourceAccessControl::class, $access);

        $media = $this->mediaRecord('secret.txt', AccessScope::RESTRICTED);

        $this->actingAs($this->user)
            ->get(route('tetranyble-storage.media.download', $media->uuid))
            ->assertForbidden();
    }

    // ---------------------------------------------------------------
    // Zip download
    // ---------------------------------------------------------------

    public function test_zip_returns_archive_with_all_permitted_files(): void
    {
        Storage::disk('local')->put('files/a.txt', 'file-a');
        Storage::disk('local')->put('files/b.txt', 'file-b');

        $a = $this->mediaRecord('a.txt', AccessScope::WORKSPACE, 'files/a.txt');
        $b = $this->mediaRecord('b.txt', AccessScope::WORKSPACE, 'files/b.txt');

        $this->actingAs($this->user)
            ->postJson(route('tetranyble-storage.media.zip'), [
                'items' => [$a->uuid, $b->uuid],
                'name'  => 'my-archive',
            ])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->assertHeader('Content-Disposition', 'attachment; filename="my-archive.zip"');
    }

    public function test_zip_requires_items_field(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('tetranyble-storage.media.zip'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_zip_requires_authentication(): void
    {
        $this->postJson(route('tetranyble-storage.media.zip'), ['items' => [Str::uuid()]])
            ->assertUnauthorized();
    }

    public function test_zip_silently_skips_items_from_other_workspace(): void
    {
        Storage::disk('local')->put('files/own.txt', 'mine');

        $otherWorkspace = Workspace::create(['name' => 'Other', 'uuid' => Str::uuid()]);
        $foreign = Media::create([
            'uuid'         => Str::uuid(),
            'workspace_id'    => $otherWorkspace->id,
            'disk'         => Disk::PRIVATE->value,
            'path'         => 'files/own.txt',
            'original_name' => 'foreign.txt',
            'access_scope' => AccessScope::WORKSPACE->value,
            'status'       => MediaStatus::READY->value,
        ]);

        // The foreign UUID is submitted but belongs to another workspace — query
        // filters by workspace_id, so it won't appear in the zip
        $this->actingAs($this->user)
            ->postJson(route('tetranyble-storage.media.zip'), [
                'items' => [$foreign->uuid],
            ])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip');
    }

    public function test_zip_defaults_archive_name_to_download(): void
    {
        Storage::disk('local')->put('files/c.txt', 'file-c');
        $c = $this->mediaRecord('c.txt', AccessScope::WORKSPACE, 'files/c.txt');

        $this->actingAs($this->user)
            ->postJson(route('tetranyble-storage.media.zip'), ['items' => [$c->uuid]])
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="download.zip"');
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function mediaRecord(
        string      $filename,
        AccessScope $scope,
        ?string     $path = null,
    ): Media {
        return Media::create([
            'uuid'          => Str::uuid(),
            'workspace_id'     => $this->workspace->id,
            'disk'          => Disk::PRIVATE->value,
            'path'          => $path ?? 'files/'.$filename,
            'original_name' => $filename,
            'mime_type'     => 'text/plain',
            'access_scope'  => $scope->value,
            'status'        => MediaStatus::READY->value,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
