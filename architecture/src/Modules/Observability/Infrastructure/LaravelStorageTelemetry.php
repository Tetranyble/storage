<?php

namespace Tetranyble\Storage\Modules\Observability\Infrastructure;

use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Tetranyble\Storage\Modules\Observability\Domain\Contracts\StorageTelemetry;
use Tetranyble\Storage\Modules\Observability\Domain\Enums\TelemetryLevel;
use Tetranyble\Storage\Events\StorageTelemetryRecorded;
use Throwable;

final class LaravelStorageTelemetry implements StorageTelemetry
{
    private const SENSITIVE_KEYS = [
        'access_token', 'refresh_token', 'token', 'password', 'password_hash',
        'secret', 'client_secret', 'credentials', 'authorization', 'path',
        'object_key', 'provider_upload_id', 'signed_url', 'url',
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Dispatcher $events,
        private readonly bool $logRecords = true,
        private readonly bool $dispatchRecords = true,
    ) {}

    public function event(string $name, array $context = [], TelemetryLevel $level = TelemetryLevel::INFO): void
    {
        $this->emit('event', $name, null, $context, $level);
    }

    public function counter(string $name, int $value = 1, array $dimensions = []): void
    {
        $this->emit('counter', $name, $value, $dimensions, TelemetryLevel::INFO);
    }

    public function gauge(string $name, int|float $value, array $dimensions = []): void
    {
        $this->emit('gauge', $name, $value, $dimensions, TelemetryLevel::INFO);
    }

    public function timing(string $name, float $milliseconds, array $dimensions = []): void
    {
        $this->emit('timing', $name, round($milliseconds, 3), $dimensions, TelemetryLevel::INFO);
    }

    private function emit(string $type, string $name, int|float|null $value, array $context, TelemetryLevel $level): void
    {
        $context = $this->sanitize($context);
        $payload = [
            'package' => 'tetranyble/storage',
            'telemetry_type' => $type,
            'telemetry_name' => $name,
            'value' => $value,
            'context' => $context,
        ];

        if ($this->logRecords) {
            try {
                $this->logger->log($level->value, 'tetranyble.storage.'.$name, $payload);
            } catch (Throwable) {
                // Telemetry must never become part of the storage operation's failure domain.
            }
        }

        if ($this->dispatchRecords) {
            try {
                $this->events->dispatch(new StorageTelemetryRecorded(
                    type: $type,
                    name: $name,
                    value: $value,
                    context: $context,
                    level: $level->value,
                    occurredAt: new \DateTimeImmutable(),
                ));
            } catch (Throwable) {
                // A host monitoring listener must not break uploads/downloads.
            }
        }
    }

    private function sanitize(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            $normalized = strtolower((string) $key);
            if (in_array($normalized, self::SENSITIVE_KEYS, true)
                || str_contains($normalized, 'password')
                || str_contains($normalized, 'secret')
                || str_contains($normalized, 'credential')
                || str_contains($normalized, 'token')
                || str_contains($normalized, 'path')
                || str_contains($normalized, 'object_key')) {
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = $this->sanitize($value);
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            } elseif ($value instanceof \Stringable) {
                $safe[$key] = (string) $value;
            }
        }

        return $safe;
    }
}
