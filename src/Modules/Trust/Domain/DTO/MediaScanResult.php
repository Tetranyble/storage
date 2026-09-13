<?php

namespace Tetranyble\Storage\Modules\Trust\Domain\DTO;

use Tetranyble\Storage\Modules\Trust\Domain\Enums\VirusScanStatus;

readonly class MediaScanResult
{
    public function __construct(
        public VirusScanStatus $status,
        public string $engine,
        public ?string $signature = null,
        public ?string $message = null,
    ) {}

    public static function clean(string $engine): self
    {
        return new self(VirusScanStatus::CLEAN, $engine);
    }

    public static function infected(string $engine, ?string $signature = null, ?string $message = null): self
    {
        return new self(VirusScanStatus::INFECTED, $engine, $signature, $message);
    }

    public static function failed(string $engine, ?string $message = null): self
    {
        return new self(VirusScanStatus::FAILED, $engine, null, $message);
    }

    public static function skipped(string $engine = 'disabled'): self
    {
        return new self(VirusScanStatus::SKIPPED, $engine);
    }
}
