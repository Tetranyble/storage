<?php

namespace Tetranyble\Storage\Tests\Unit;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tetranyble\Storage\Modules\Access\Domain\Exceptions\AccessDeniedException;
use Tetranyble\Storage\Modules\Access\Domain\Exceptions\AuthenticationRequiredException;
use Tetranyble\Storage\Modules\Sharing\Domain\Exceptions\InvalidSharePasswordException;
use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\InvalidStorageOperationException;
use Tetranyble\Storage\Modules\Trust\Domain\Exceptions\MediaQuarantinedException;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;
use Tetranyble\Storage\Modules\Sharing\Domain\Exceptions\ShareDownloadLimitReachedException;
use Tetranyble\Storage\Modules\Sharing\Domain\Exceptions\ShareDownloadNotAllowedException;
use Tetranyble\Storage\Modules\Sharing\Domain\Exceptions\ShareExpiredException;
use Tetranyble\Storage\Http\Middleware\HandleStorageExceptions;
use Tetranyble\Storage\Tests\PackageTestCase;

class DomainExceptionBoundaryTest extends PackageTestCase
{
    #[DataProvider('httpMappings')]
    public function test_http_boundary_maps_package_exceptions_to_expected_status(string $exceptionClass, int $status): void
    {
        $middleware = new HandleStorageExceptions();

        try {
            $middleware->handle(Request::create('/storage'), function () use ($exceptionClass): never {
                throw new $exceptionClass();
            });
            $this->fail('Expected package exception to be translated to an HTTP exception.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
            $this->assertInstanceOf($exceptionClass, $exception->getPrevious());
        }
    }

    public static function httpMappings(): array
    {
        return [
            [AuthenticationRequiredException::class, 401],
            [AccessDeniedException::class, 403],
            [InvalidSharePasswordException::class, 403],
            [ShareDownloadNotAllowedException::class, 403],
            [ResourceNotFoundException::class, 404],
            [ShareExpiredException::class, 410],
            [MediaQuarantinedException::class, 423],
            [InvalidStorageOperationException::class, 422],
            [ShareDownloadLimitReachedException::class, 429],
        ];
    }

    public function test_domain_and_application_sources_do_not_call_laravel_abort_helpers(): void
    {
        $modules = dirname(__DIR__, 2).'/src/Modules';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($modules));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            if (! str_contains($path, '/Domain/') && ! str_contains($path, '/Application/')) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $this->assertDoesNotMatchRegularExpression(
                '/\\babort(?:_if|_unless)?\\s*\\(/',
                $source,
                $file->getPathname().' must throw a package exception instead of terminating through HTTP helpers.',
            );
        }
    }

}
