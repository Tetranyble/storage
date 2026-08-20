<?php

namespace Tetranyble\Storage\Tests\Unit;

use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Illuminate\Support\Facades\Storage;
use Tetranyble\Storage\Tests\PackageTestCase;

class FileSystemTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_url_returns_absolute_url_unchanged(): void
    {
        $fs = $this->app->make(FileSystemContract::class);
        $url = 'https://example.com/images/logo.png';

        $this->assertSame($url, $fs->url($url, Disk::PUBLIC));
    }

    public function test_url_generates_public_url_for_relative_path(): void
    {
        $fs = $this->app->make(FileSystemContract::class);
        $path = 'images/logo.png';

        Storage::disk('public')->put($path, 'dummy');

        $result = $fs->url($path, Disk::PUBLIC);

        $this->assertStringContainsString($path, $result);
    }

    public function test_copy_across_disks(): void
    {
        $fs = $this->app->make(FileSystemContract::class);

        Storage::disk('local')->put('foo.txt', 'hello');

        $copied = $fs->copy('foo.txt', 'foo.txt', Disk::PRIVATE, Disk::PUBLIC);

        $this->assertTrue($copied);
        Storage::disk('local')->assertExists('foo.txt');
        Storage::disk('public')->assertExists('foo.txt');
    }

    public function test_move_across_disks(): void
    {
        $fs = $this->app->make(FileSystemContract::class);

        Storage::disk('local')->put('hello.txt', 'world');

        $moved = $fs->move('hello.txt', 'moved.txt', Disk::PRIVATE, Disk::PUBLIC);

        $this->assertTrue($moved);
        Storage::disk('local')->assertMissing('hello.txt');
        Storage::disk('public')->assertExists('moved.txt');
    }
}
