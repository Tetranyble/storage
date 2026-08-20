<?php

namespace Tetranyble\Storage\Tests\Unit;

use Tetranyble\Storage\Domain\FileSystem\MediaService;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Tests\Fixtures\Models\Loan;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Mockery;

class MediableTraitTest extends PackageTestCase
{
    public function test_attach_media_delegates_to_media_service(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $loan = Loan::create(['workspace_id' => $workspace->id]);
        $file = UploadedFile::fake()->create('payslip.pdf', 50, 'application/pdf');
        $media = Media::make();

        $mock = Mockery::mock(MediaService::class);
        $mock->shouldReceive('attachSourceFor')->once()->andReturn($media);
        $this->app->instance(MediaService::class, $mock);

        $result = $loan->attachMedia($file, 'Payslip', '', 'media', MediaPurpose::BANK_STATEMENT);

        $this->assertSame($media, $result);
    }

    public function test_attach_existing_media_by_id_uses_media_service(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $loan = Loan::create(['workspace_id' => $workspace->id]);
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'disk' => 'public',
            'use' => MediaPurpose::GENERAL,
            'current' => true,
        ]);

        $mock = Mockery::mock(MediaService::class);
        $mock->shouldReceive('attachExistingMediaToModel')->once()->andReturn($media);
        $this->app->instance(MediaService::class, $mock);

        $result = $loan->attachExistingMediaById($media->id, MediaPurpose::BANK_STATEMENT);

        $this->assertNotNull($result);
        $this->assertSame($media->id, $result->id);
    }

    public function test_trait_scopes_read_update_trash_and_restore_to_own_media(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $loan = Loan::create(['workspace_id' => $workspace->id]);
        $otherLoan = Loan::create(['workspace_id' => $workspace->id]);
        $media = $loan->media()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'disk' => 'public',
            'use' => MediaPurpose::GENERAL,
            'current' => true,
        ]);
        $otherMedia = $otherLoan->media()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'disk' => 'public',
            'use' => MediaPurpose::GENERAL,
            'current' => true,
        ]);

        $updated = $loan->updateMediaMetadata($media->uuid, [
            'description' => 'Signed agreement',
            'path' => 'must-not-change',
        ]);

        $this->assertSame('Signed agreement', $updated->description);
        $this->assertNull($updated->path);
        $this->assertNull($loan->findMedia($otherMedia->uuid));
        $this->assertTrue($loan->trashMediaItem($media));
        $this->assertTrue($media->fresh()->trashed());
        $this->assertFalse($loan->restoreMediaItem($media->uuid)->trashed());
    }
}
