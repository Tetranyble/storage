<?php

namespace Tetranyble\Storage\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tetranyble\Storage\Http\Middleware\AddStorageSecurityHeaders;
use Tetranyble\Storage\Http\Middleware\HandleStorageExceptions;
use Tetranyble\Storage\Infrastructure\Laravel\StorageConfigurationValidator;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;
use Tetranyble\Storage\Tests\PackageTestCase;

final class HttpBoundaryHardeningTest extends PackageTestCase
{
    public function test_json_domain_failures_have_stable_machine_readable_contract(): void
    {
        $request = Request::create('/storage/media/missing', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
        $response = app(HandleStorageExceptions::class)->handle($request, static function (): never {
            throw new ResourceNotFoundException('Internal persistence detail that must not leak.');
        });

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([
            'success' => false,
            'error' => [
                'code' => 'resource_not_found',
                'message' => 'Resource not found.',
            ],
        ], $response->getData(true));
    }

    public function test_unexpected_json_failures_are_reported_without_leaking_internal_details(): void
    {
        $request = Request::create('/storage/media/failure', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
        $response = app(HandleStorageExceptions::class)->handle($request, static function (): never {
            throw new RuntimeException('Secret backend detail.');
        });

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame([
            'success' => false,
            'error' => [
                'code' => 'internal_error',
                'message' => 'The storage request could not be completed.',
            ],
        ], $response->getData(true));
    }

    public function test_security_headers_are_applied_to_package_responses(): void
    {
        $request = Request::create('/storage');
        $response = app(AddStorageSecurityHeaders::class)->handle(
            $request,
            static fn () => response('ok'),
        );

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function test_package_routes_have_public_and_authenticated_rate_limits(): void
    {
        $public = Route::getRoutes()->getByName('tetranyble-storage.shares.download');
        $protected = Route::getRoutes()->getByName('tetranyble-storage.media.download');

        $this->assertNotNull($public);
        $this->assertNotNull($protected);
        $this->assertContains('throttle:tetranyble-storage-public', $public->gatherMiddleware());
        $this->assertContains('throttle:tetranyble-storage-authenticated', $protected->gatherMiddleware());
    }

    public function test_processing_queue_retry_after_must_exceed_worker_timeout(): void
    {
        config()->set('tetranyble-storage.processing.enabled', true);
        config()->set('tetranyble-storage.processing.auto_dispatch', true);
        config()->set('tetranyble-storage.processing.inline', false);
        config()->set('tetranyble-storage.processing.connection', 'database');
        config()->set('tetranyble-storage.processing.timeout_seconds', 120);
        config()->set('queue.connections.database.retry_after', 90);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('retry_after must be greater than');

        app(StorageConfigurationValidator::class)->validate();
    }

    public function test_enabled_protected_routes_fail_closed_without_auth_middleware(): void
    {
        config()->set('tetranyble-storage.routes.enabled', true);
        config()->set('tetranyble-storage.routes.middleware', ['web']);
        config()->set('tetranyble-storage.routes.allow_unauthenticated_protected_routes', false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Protected storage routes must include auth middleware');

        app(StorageConfigurationValidator::class)->validate();
    }
}
