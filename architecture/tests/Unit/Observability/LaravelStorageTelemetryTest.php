<?php

namespace Tetranyble\Storage\Tests\Unit\Observability;

use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tetranyble\Storage\Modules\Observability\Domain\Enums\TelemetryLevel;
use Tetranyble\Storage\Modules\Observability\Infrastructure\LaravelStorageTelemetry;

class LaravelStorageTelemetryTest extends TestCase
{
    public function test_logger_and_listener_failures_never_escape_into_storage_operations(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $events = Mockery::mock(Dispatcher::class);
        $logger->shouldReceive('log')->once()->andThrow(new RuntimeException('logger unavailable'));
        $events->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('metrics listener unavailable'));

        $telemetry = new LaravelStorageTelemetry($logger, $events);
        $telemetry->event('upload.failed', ['workspace_id' => 1], TelemetryLevel::ERROR);

        $this->addToAssertionCount(1);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
