<?php

namespace Tetranyble\Storage\Domain\FileSystem\DTO;

use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\FileSystem\Enums\UploadStrategy;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Enums\MediaRevisionEventType;
use Tetranyble\Storage\Support\StorageConfig;
use Illuminate\Database\Eloquent\Model;

readonly class MediaUploadOptions
{
    public function __construct(
        public ?Model $model = null,
        public ?int $workspaceId = null,
        public ?int $userId = null,
        public ?int $folderId = null,
        public ?Disk $disk = null,
        public ?string $directory = null,
        public MediaPurpose $purpose = MediaPurpose::GENERAL,
        public ?string $label = null,
        public ?string $title = null,
        public ?string $visibility = null,
        public UploadStrategy $strategy = UploadStrategy::SINGLE,
        public ?string $module = null,
        public array $customProperties = [],
        public bool $dispatchPostProcessing = false,
        public bool $replaceExisting = false,
        public bool $makeCurrent = true,
        public bool $temporary = false,
        public ?\DateTimeInterface $expiresAt = null,
        public bool $preserveFilename = false,
        public ?string $originalName = null,
        public ?string $attribution = null,
        public ?string $intendedUsage = null,
        public ?string $redirectTo = null,
        public ?int $replacesMediaId = null,
        public ?MediaRevisionEventType $auditEventType = null,
        public ?int $auditSourceMediaId = null,
        public ?int $auditSupersededMediaId = null,
        public array $auditMeta = [],
    ) {}

    public static function forModel(
        Model $model,
        MediaPurpose $purpose = MediaPurpose::GENERAL,
        ?string $directory = null,
        ?Disk $disk = null,
        ?int $userId = null,
        ?string $module = null,
        bool $replaceExisting = false,
        array $customProperties = [],
        UploadStrategy $strategy = UploadStrategy::SINGLE,
        ?string $label = null,
        ?string $title = null,
        ?string $attribution = null,
        ?int $folderId = null,
        bool $makeCurrent = true,
    ): self {
        return new self(
            model: $model,
            workspaceId: StorageConfig::actorWorkspaceId($model),
            userId: $userId,
            folderId: $folderId,
            disk: $disk,
            directory: $directory,
            purpose: $purpose,
            label: $label,
            title: $title,
            strategy: $strategy,
            module: $module,
            customProperties: $customProperties,
            replaceExisting: $replaceExisting,
            makeCurrent: $makeCurrent,
            attribution: $attribution,
        );
    }

    public static function forStandalone(
        ?int $workspaceId = null,
        MediaPurpose $purpose = MediaPurpose::GENERAL,
        ?string $directory = null,
        ?Disk $disk = null,
        ?int $userId = null,
        ?string $module = null,
        ?int $folderId = null,
        bool $temporary = true,
        ?\DateTimeInterface $expiresAt = null,
        bool $dispatchPostProcessing = false,
        array $customProperties = [],
        UploadStrategy $strategy = UploadStrategy::SINGLE,
        ?string $label = null,
        ?string $title = null,
        ?string $attribution = null,
        ?string $redirectTo = null,
        ?string $intendedUsage = null,
        bool $makeCurrent = true,
    ): self {
        return new self(
            workspaceId: $workspaceId,
            userId: $userId,
            folderId: $folderId,
            disk: $disk,
            directory: $directory,
            purpose: $purpose,
            label: $label,
            title: $title,
            strategy: $strategy,
            module: $module,
            customProperties: $customProperties,
            dispatchPostProcessing: $dispatchPostProcessing,
            makeCurrent: $makeCurrent,
            temporary: $temporary,
            expiresAt: $expiresAt,
            attribution: $attribution,
            intendedUsage: $intendedUsage,
            redirectTo: $redirectTo,
        );
    }

    public function description(): string
    {
        return $this->title ?? $this->label ?? '';
    }
}
