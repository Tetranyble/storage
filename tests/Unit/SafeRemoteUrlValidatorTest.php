<?php

namespace Tetranyble\Storage\Tests\Unit;

use Tetranyble\Storage\Domain\FileSystem\Exceptions\RemoteDownloadException;
use Tetranyble\Storage\Domain\FileSystem\SafeRemoteUrlValidator;
use Tetranyble\Storage\Tests\PackageTestCase;

class SafeRemoteUrlValidatorTest extends PackageTestCase
{
    public function test_private_and_non_http_urls_are_rejected(): void
    {
        $validator = new SafeRemoteUrlValidator();

        foreach (['http://127.0.0.1/secret', 'http://[::1]/secret', 'file:///etc/passwd'] as $url) {
            try {
                $validator->assertSafe($url);
                $this->fail("Expected {$url} to be rejected.");
            } catch (RemoteDownloadException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_host_allowlist_is_enforced(): void
    {
        config()->set('tetranyble-storage.remote.allowed_hosts', ['assets.example.com']);
        config()->set('tetranyble-storage.remote.block_private_networks', false);
        $validator = new SafeRemoteUrlValidator();

        $validator->assertSafe('https://cdn.assets.example.com/photo.png');
        $this->expectException(RemoteDownloadException::class);
        $validator->assertSafe('https://example.org/photo.png');
    }
}
