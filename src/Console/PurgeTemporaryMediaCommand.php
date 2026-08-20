<?php

namespace Tetranyble\Storage\Console;

use Tetranyble\Storage\Domain\FileSystem\MediaService;
use Tetranyble\Storage\Models\Media;
use Illuminate\Console\Command;

class PurgeTemporaryMediaCommand extends Command
{
    protected $signature = 'tetranyble-storage:purge-temp';

    protected $description = 'Purge expired temporary media files';

    public function handle(MediaService $mediaService): int
    {
        $totalPurged = 0;

        Media::temporary()
            ->whereNotNull('temporary_expires_at')
            ->where('temporary_expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use ($mediaService, &$totalPurged): void {
                foreach ($chunk as $media) {
                    $mediaService->deleteMediaItem($media);
                    $totalPurged++;
                }
            });

        $this->info('Purged '.$totalPurged.' temporary media items.');

        return self::SUCCESS;
    }
}
