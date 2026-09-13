<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Upload\Domain\Aggregates;

use Tetranyble\Storage\Modules\Upload\Domain\Enums\UploadSessionStatus;
use Tetranyble\Storage\Modules\Upload\Domain\Exceptions\UploadSessionConflictException;

/** Framework-free state machine for resumable-upload session lifecycle rules. */
final class ResumableUploadLifecycle
{
    private function __construct(
        private UploadSessionStatus $status,
        private readonly int|string|null $sessionId,
    ) {}

    public static function reconstitute(
        UploadSessionStatus $status,
        int|string|null $sessionId = null,
    ): self {
        return new self($status, $sessionId);
    }

    public function status(): UploadSessionStatus
    {
        return $this->status;
    }

    public function assertReceivesChunks(): void
    {
        match ($this->status) {
            UploadSessionStatus::PENDING, UploadSessionStatus::UPLOADING => null,
            UploadSessionStatus::ASSEMBLING => throw $this->conflictException(
                'Upload session is being finalized and cannot receive more chunks.',
                'session_locked',
            ),
            UploadSessionStatus::EXPIRED => throw $this->conflictException('Upload session has expired.', 'session_expired'),
            UploadSessionStatus::CONFLICTED => throw $this->conflictException(
                'Upload session is conflicted and cannot receive more chunks.',
                'session_conflicted',
            ),
            UploadSessionStatus::CANCELLED => throw $this->conflictException(
                'Upload session has been cancelled.',
                'session_cancelled',
            ),
            UploadSessionStatus::FINALIZED => throw $this->conflictException(
                'Upload session has already been finalized.',
                'session_finalized',
            ),
        };
    }

    public function synchronizeProgress(int $receivedChunks): void
    {
        if ($receivedChunks < 0) {
            throw new \InvalidArgumentException('Received chunk count cannot be negative.');
        }

        $this->assertReceivesChunks();
        $this->status = $receivedChunks > 0
            ? UploadSessionStatus::UPLOADING
            : UploadSessionStatus::PENDING;
    }

    public function claimAssembly(): void
    {
        match ($this->status) {
            UploadSessionStatus::PENDING, UploadSessionStatus::UPLOADING => $this->status = UploadSessionStatus::ASSEMBLING,
            UploadSessionStatus::ASSEMBLING => throw $this->conflictException(
                'Upload session is already being finalized.',
                'session_locked',
            ),
            UploadSessionStatus::FINALIZED => null,
            UploadSessionStatus::CONFLICTED => throw $this->conflictException(
                'Conflicted upload sessions cannot be finalized.',
                'session_conflicted',
            ),
            UploadSessionStatus::CANCELLED => throw $this->conflictException(
                'Cancelled upload sessions cannot be finalized.',
                'session_cancelled',
            ),
            UploadSessionStatus::EXPIRED => throw $this->conflictException('Upload session has expired.', 'session_expired'),
        };
    }

    public function completeAssembly(): void
    {
        if ($this->status !== UploadSessionStatus::ASSEMBLING) {
            throw $this->conflictException('Upload session is not being finalized.', 'invalid_status');
        }

        $this->status = UploadSessionStatus::FINALIZED;
    }

    public function releaseAssembly(bool $hasChunks): void
    {
        if ($this->status === UploadSessionStatus::ASSEMBLING) {
            $this->status = $hasChunks ? UploadSessionStatus::UPLOADING : UploadSessionStatus::PENDING;
        }
    }

    public function cancel(): void
    {
        if ($this->status === UploadSessionStatus::FINALIZED) {
            throw $this->conflictException('Finalized upload sessions cannot be cancelled.', 'session_finalized');
        }

        if ($this->status === UploadSessionStatus::ASSEMBLING) {
            throw $this->conflictException('Upload sessions being finalized cannot be cancelled.', 'session_locked');
        }

        $this->status = UploadSessionStatus::CANCELLED;
    }

    public function expire(): bool
    {
        if (in_array($this->status, [
            UploadSessionStatus::ASSEMBLING,
            UploadSessionStatus::FINALIZED,
            UploadSessionStatus::CANCELLED,
            UploadSessionStatus::CONFLICTED,
        ], true)) {
            return false;
        }

        $changed = $this->status !== UploadSessionStatus::EXPIRED;
        $this->status = UploadSessionStatus::EXPIRED;

        return $changed;
    }

    public function conflict(): void
    {
        $this->assertReceivesChunks();
        $this->status = UploadSessionStatus::CONFLICTED;
    }

    private function conflictException(string $message, string $reason): UploadSessionConflictException
    {
        return new UploadSessionConflictException(
            $message,
            $reason,
            $this->sessionId === null ? [] : ['session_id' => $this->sessionId],
        );
    }
}
