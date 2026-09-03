<?php

namespace Tetranyble\Storage\Console;

use Tetranyble\Storage\Domain\Media\MediaDeletionService;
use Tetranyble\Storage\Models\Media;
use Illuminate\Console\Command;

class PurgeTemporaryMediaCommand extends Command
{
    protected $signature = 'tetranyble-storage:purge-temp';

    protected $description = 'Purge expired temporary media files';

    public function handle(MediaDeletionService $deletion): int
    {
        $totalPurged = 0;

        Media::temporary()
            ->whereNotNull('temporary_expires_at')
            ->where('temporary_expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use ($deletion, &$totalPurged): void {
                foreach ($chunk as $media) {
                    $deletion->delete($media);
                    $totalPurged++;
                }
            });

        $this->info('Purged '.$totalPurged.' temporary media items.');

        return self::SUCCESS;
    }
}
