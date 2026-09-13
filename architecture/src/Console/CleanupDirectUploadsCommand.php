<?php

namespace Tetranyble\Storage\Console;

use Illuminate\Console\Command;
use Tetranyble\Storage\Modules\DirectUpload\Application\Contracts\DirectUploadManager;

final class CleanupDirectUploadsCommand extends Command
{
    protected $signature = 'storage:cleanup-direct-uploads {--limit=100 : Maximum sessions to expire/retry per pass}';

    protected $description = 'Expire abandoned direct uploads, release reservations, and abort unfinished multipart uploads.';

    public function handle(DirectUploadManager $uploads): int
    {
        $result = $uploads->cleanupExpired((int) $this->option('limit'));

        $this->info(sprintf(
            'Direct upload cleanup: %d expired, %d cleaned, %d cleanup failures.',
            (int) ($result['expired'] ?? 0),
            (int) ($result['cleaned'] ?? 0),
            (int) ($result['failed'] ?? 0),
        ));

        return ((int) ($result['failed'] ?? 0)) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
