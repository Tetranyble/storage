<?php

namespace Tetranyble\Storage\Tests\Feature;

use Illuminate\Support\Str;
use Tetranyble\Storage\Http\Mail\LaravelMediaMailService;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaStatus;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;

class MediaEmailLinkTest extends PackageTestCase
{
    public function test_email_link_for_media_creates_a_share_and_returns_a_public_url_payload(): void
    {
        $workspace = Workspace::create([
            'uuid' => Str::uuid(),
            'name' => 'Acme',
        ]);

        $media = Media::create([
            'uuid' => Str::uuid(),
            'workspace_id' => $workspace->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/acme/contracts/nda.pdf',
            'original_name' => 'nda.pdf',
            'mime_type' => 'application/pdf',
            'status' => MediaStatus::READY,
        ]);

        $mail = $this->app->make(LaravelMediaMailService::class);
        $payload = $mail->publicLinkPayload($workspace, $media, ttlMinutes: 30, maxDownloads: 3);

        $this->assertSame('url', $payload->type);
        $this->assertSame('nda.pdf', $payload->filename);
        $this->assertSame('application/pdf', $payload->mime);
        $this->assertSame('attachment', $payload->disposition);
        $this->assertStringContainsString('/storage/shares/', $payload->url);
        $this->assertNotNull($payload->share?->id);
        $this->assertSame(3, $payload->share?->max_downloads);
        $this->assertSame($payload->share?->token, basename(parse_url($payload->url, PHP_URL_PATH)));
    }
}
