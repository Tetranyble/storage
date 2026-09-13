<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Upload\Infrastructure\Application\Adapters;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Tetranyble\Storage\Modules\Storage\Application\DTO\IncomingFile;
use Tetranyble\Storage\Modules\Upload\Application\Contracts\ResumableUploadManager;
use Tetranyble\Storage\Modules\Upload\Application\DTO\UploadSessionOptions;
use Tetranyble\Storage\Modules\Upload\Infrastructure\ResumableUploadService;

final class EloquentResumableUploadManager implements ResumableUploadManager
{
    public function __construct(private readonly ResumableUploadService $uploads) {}

    public function startSession(UploadSessionOptions $options): object
    {
        return $this->uploads->startSession($options);
    }

    public function appendChunk(
        object $session,
        IncomingFile $chunk,
        int $chunkNumber,
        ?string $checksum = null,
    ): object {
        return $this->uploads->appendChunk($this->model($session), $chunk, $chunkNumber, $checksum);
    }

    public function progress(object $session): array
    {
        return $this->uploads->progress($this->model($session));
    }

    public function finalizeSession(object $session): object
    {
        return $this->uploads->finalizeSession($this->model($session));
    }

    public function cancelSession(object $session): void
    {
        $this->uploads->cancelSession($this->model($session));
    }

    private function model(object $value): Model
    {
        if (! $value instanceof Model) {
            throw new InvalidArgumentException('Expected an Eloquent resumable-upload session.');
        }

        return $value;
    }
}
