<?php

namespace Tetranyble\Storage\Tests\Unit;

use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Enums\MediaStatus;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaMailAttachmentTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_to_mail_attachment_builds_a_laravel_attachment_with_filename_and_mime(): void
    {
        Storage::disk('public')->put('mail/invoice.pdf', 'pdf-bytes');
        $workspace = Workspace::create(['uuid' => Str::uuid(), 'name' => 'Acme']);

        $media = Media::create([
            'uuid' => Str::uuid(),
            'workspace_id' => $workspace->id,
            'disk' => Disk::PUBLIC,
            'path' => 'mail/invoice.pdf',
            'original_name' => 'invoice-final.pdf',
            'mime_type' => 'application/pdf',
            'status' => MediaStatus::READY,
        ]);

        $attachment = $media->toMailAttachment();

        $resolved = $attachment->attachWith(
            fn (string $path, Attachment $mailAttachment) => [
                'strategy' => 'path',
                'path' => $path,
                'name' => $mailAttachment->as,
                'mime' => $mailAttachment->mime,
            ],
            fn (callable $data, Attachment $mailAttachment) => [
                'strategy' => 'data',
                'data' => $data(),
                'name' => $mailAttachment->as,
                'mime' => $mailAttachment->mime,
            ],
        );

        $this->assertSame('data', $resolved['strategy']);
        $this->assertSame('pdf-bytes', $resolved['data']);
        $this->assertSame('invoice-final.pdf', $resolved['name']);
        $this->assertSame('application/pdf', $resolved['mime']);
    }

    public function test_to_mail_base64_payload_returns_mail_safe_metadata(): void
    {
        Storage::disk('local')->put('mail/report.csv', "a,b\n1,2\n");
        $workspace = Workspace::create(['uuid' => Str::uuid(), 'name' => 'Acme']);

        $media = Media::create([
            'uuid' => Str::uuid(),
            'workspace_id' => $workspace->id,
            'disk' => Disk::PRIVATE,
            'path' => 'mail/report.csv',
            'original_name' => 'report.csv',
            'mime_type' => 'text/csv',
            'status' => MediaStatus::READY,
        ]);

        $payload = $media->toMailBase64Payload();

        $this->assertSame('base64', $payload->type);
        $this->assertSame('report.csv', $payload->filename);
        $this->assertSame('text/csv', $payload->mime);
        $this->assertSame('attachment', $payload->disposition);
        $this->assertSame(base64_encode("a,b\n1,2\n"), $payload->content);
    }
}
