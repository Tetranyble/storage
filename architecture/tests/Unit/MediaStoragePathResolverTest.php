<?php

namespace Tetranyble\Storage\Tests\Unit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\Media\MediaStoragePathResolver;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;

class MediaStoragePathResolverTest extends PackageTestCase
{
    public function test_external_media_disk_mapping_is_isolated_from_media_persistence(): void
    {
        $resolver = app(MediaStoragePathResolver::class);

        $this->assertSame(Disk::YOUTUBE, $resolver->diskForExternalUrl('https://youtu.be/example'));
        $this->assertSame(Disk::VIMEO, $resolver->diskForExternalUrl('https://vimeo.com/123'));
        $this->assertSame(Disk::PUBLIC, $resolver->diskForExternalUrl('https://cdn.example.test/file.mp4'));
    }

    public function test_upload_directory_and_filename_are_resolved_without_media_service(): void
    {
        Carbon::setTestNow('2026-09-02 19:00:00');

        try {
            $workspace = Workspace::create([
                'uuid' => (string) Str::uuid(),
                'name' => 'Path Test',
            ]);

            $resolver = app(MediaStoragePathResolver::class);
            $options = MediaUploadOptions::forStandalone(
                workspaceId: $workspace->id,
                purpose: MediaPurpose::GENERAL,
                directory: 'documents',
                module: 'documents',
            );

            $this->assertSame(
                'workspaces/'.$workspace->uuid.'/documents/2026/09/02/general',
                $resolver->uploadDirectory($options, $workspace),
            );
            $this->assertSame('my-report.PDF', $resolver->storedFilename('My Report.PDF', true));

            $generated = $resolver->storedFilename('My Report.PDF');
            $this->assertMatchesRegularExpression('/^my-report-[a-z0-9]{8}\.PDF$/', $generated);
        } finally {
            Carbon::setTestNow();
        }
    }
}
