<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\DirectUpload\Domain\Aggregates;

use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadStatus;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Exceptions\DirectUploadConflictException;

/**
 * Framework-free state machine for a direct-upload session.
 *
 * Persistence timestamps and provider cleanup remain adapter concerns; this
 * aggregate owns which lifecycle transitions are legal and when quota
 * reservations become releasable.
 */
final class DirectUploadLifecycle
{
    private function __construct(
        private DirectUploadStatus $status,
        private int $reservedBytes,
    ) {
        if ($reservedBytes < 0) {
            throw new \InvalidArgumentException('Reserved upload bytes cannot be negative.');
        }
    }

    public static function reconstitute(DirectUploadStatus $status, int $reservedBytes): self
    {
        return new self($status, $reservedBytes);
    }

    public function status(): DirectUploadStatus
    {
        return $this->status;
    }

    public function reservedBytes(): int
    {
        return $this->reservedBytes;
    }

    public function beginUploading(): void
    {
        if ($this->status !== DirectUploadStatus::PENDING) {
            throw $this->invalidTransition('begin uploading');
        }

        $this->status = DirectUploadStatus::UPLOADING;
    }

    public function assertWritable(): void
    {
        if ($this->status !== DirectUploadStatus::UPLOADING) {
            throw new DirectUploadConflictException(
                sprintf('Direct upload session is not writable in status [%s].', $this->status->value),
                'invalid_status',
            );
        }
    }

    public function claimFinalization(): void
    {
        if ($this->status === DirectUploadStatus::FINALIZED) {
            return;
        }

        if ($this->status === DirectUploadStatus::FINALIZING) {
            throw new DirectUploadConflictException(
                'Direct upload finalization is already in progress.',
                'finalizing',
            );
        }

        if ($this->status !== DirectUploadStatus::UPLOADING) {
            throw new DirectUploadConflictException(
                sprintf('Direct upload session cannot be finalized from status [%s].', $this->status->value),
                'invalid_status',
            );
        }

        $this->status = DirectUploadStatus::FINALIZING;
    }

    public function completeFinalization(): void
    {
        if ($this->status !== DirectUploadStatus::FINALIZING) {
            throw $this->invalidTransition('complete finalization');
        }

        $this->status = DirectUploadStatus::FINALIZED;
        // The reservation becomes committed media usage, so it is cleared from
        // the session without being released from workspace usage.
        $this->reservedBytes = 0;
    }

    public function releaseFinalization(): void
    {
        if ($this->status === DirectUploadStatus::FINALIZING) {
            $this->status = DirectUploadStatus::UPLOADING;
        }
    }

    /** @return int number of quota-reserved bytes that must be released */
    public function cancel(): int
    {
        if ($this->status === DirectUploadStatus::FINALIZED) {
            throw new DirectUploadConflictException(
                'Finalized direct uploads cannot be cancelled.',
                'already_finalized',
            );
        }

        if (in_array($this->status, [
            DirectUploadStatus::CANCELLED,
            DirectUploadStatus::EXPIRED,
            DirectUploadStatus::FAILED,
        ], true)) {
            return 0;
        }

        if ($this->status === DirectUploadStatus::FINALIZING) {
            throw new DirectUploadConflictException(
                'Direct upload finalization is already in progress.',
                'finalizing',
            );
        }

        $released = $this->reservedBytes;
        $this->reservedBytes = 0;
        $this->status = DirectUploadStatus::CANCELLED;

        return $released;
    }

    /** @return int number of quota-reserved bytes that must be released */
    public function expire(): int
    {
        if (! in_array($this->status, [DirectUploadStatus::PENDING, DirectUploadStatus::UPLOADING], true)) {
            return 0;
        }

        $released = $this->reservedBytes;
        $this->reservedBytes = 0;
        $this->status = DirectUploadStatus::EXPIRED;

        return $released;
    }

    /** @return int number of quota-reserved bytes that must be released */
    public function fail(): int
    {
        if ($this->status === DirectUploadStatus::FINALIZED) {
            return 0;
        }

        $released = $this->reservedBytes;
        $this->reservedBytes = 0;
        $this->status = DirectUploadStatus::FAILED;

        return $released;
    }

    private function invalidTransition(string $transition): DirectUploadConflictException
    {
        return new DirectUploadConflictException(
            sprintf('Cannot %s from direct-upload status [%s].', $transition, $this->status->value),
            'invalid_status',
        );
    }
}
