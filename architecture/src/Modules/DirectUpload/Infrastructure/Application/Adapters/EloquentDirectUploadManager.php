<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\DirectUpload\Infrastructure\Application\Adapters;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Tetranyble\Storage\Modules\DirectUpload\Application\Contracts\DirectUploadManager;
use Tetranyble\Storage\Modules\DirectUpload\Application\DTO\DirectUploadRequest;
use Tetranyble\Storage\Modules\DirectUpload\Application\DTO\DirectUploadStartResult;
use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadProviderPlan;
use Tetranyble\Storage\Modules\DirectUpload\Infrastructure\DirectUploadService;

final class EloquentDirectUploadManager implements DirectUploadManager
{
    public function __construct(private readonly DirectUploadService $uploads) {}

    public function start(DirectUploadRequest $request): DirectUploadStartResult
    {
        return $this->uploads->start($request);
    }

    public function refresh(object $session, array $partNumbers = []): DirectUploadProviderPlan
    {
        return $this->uploads->refresh($this->model($session), $partNumbers);
    }

    public function signParts(object $session, array $partNumbers): array
    {
        return $this->uploads->signParts($this->model($session), $partNumbers);
    }

    public function finalize(object $session, array $parts = []): object
    {
        return $this->uploads->finalize($this->model($session), $parts);
    }

    public function cancel(object $session): void
    {
        $this->uploads->cancel($this->model($session));
    }

    public function progress(object $session): array
    {
        return $this->uploads->progress($this->model($session));
    }

    public function cleanupExpired(int $limit = 100): array
    {
        return $this->uploads->cleanupExpired($limit);
    }

    private function model(object $value): Model
    {
        if (! $value instanceof Model) {
            throw new InvalidArgumentException('Expected an Eloquent direct-upload session.');
        }

        return $value;
    }
}
