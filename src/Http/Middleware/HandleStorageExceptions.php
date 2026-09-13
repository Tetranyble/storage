<?php

namespace Tetranyble\Storage\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tetranyble\Storage\Http\Responses\ApiErrorResponder;
use Tetranyble\Storage\Modules\Access\Domain\Exceptions\AccessDeniedException;
use Tetranyble\Storage\Modules\Access\Domain\Exceptions\AuthenticationRequiredException;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Exceptions\DirectUploadConflictException;
use Tetranyble\Storage\Modules\Sharing\Domain\Exceptions\InvalidSharePasswordException;
use Tetranyble\Storage\Modules\Sharing\Domain\Exceptions\ShareDownloadLimitReachedException;
use Tetranyble\Storage\Modules\Sharing\Domain\Exceptions\ShareDownloadNotAllowedException;
use Tetranyble\Storage\Modules\Sharing\Domain\Exceptions\ShareExpiredException;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\StorageException;
use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\InvalidStorageOperationException;
use Tetranyble\Storage\Modules\Trust\Domain\Exceptions\MediaQuarantinedException;

final class HandleStorageExceptions
{
    public function __construct(private readonly ApiErrorResponder $errors = new ApiErrorResponder()) {}

    public function handle(Request $request, Closure $next): mixed
    {
        try {
            return $next($request);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                return $this->errors->error('validation_failed', 'The request data is invalid.', 422, [
                    'fields' => $exception->errors(),
                ]);
            }
            throw $exception;
        } catch (AuthenticationException|AuthenticationRequiredException $exception) {
            return $this->translate($request, $exception, 401, 'authentication_required', 'Authentication is required.');
        } catch (AuthorizationException|AccessDeniedException $exception) {
            return $this->translate($request, $exception, 403, 'access_denied', 'Access denied.');
        } catch (ModelNotFoundException|ResourceNotFoundException $exception) {
            return $this->translate($request, $exception, 404, 'resource_not_found', 'Resource not found.');
        } catch (StorageException $exception) {
            $status = $this->statusFor($exception);
            return $this->translate($request, $exception, $status, $this->codeFor($exception), $this->messageFor($exception));
        } catch (HttpException $exception) {
            if (! $request->expectsJson()) {
                throw $exception;
            }

            $status = $exception->getStatusCode();
            $code = $status === 429 ? 'rate_limited' : 'http_error';
            $message = $status === 429 ? 'Too many requests.' : 'The HTTP request could not be completed.';

            return $this->errors->error($code, $message, $status, headers: $exception->getHeaders());
        } catch (\Throwable $exception) {
            report($exception);

            if ($request->expectsJson()) {
                return $this->errors->error(
                    'internal_error',
                    'The storage request could not be completed.',
                    500,
                );
            }

            throw $exception;
        }
    }

    private function translate(Request $request, \Throwable $exception, int $status, string $code, string $message): mixed
    {
        if ($request->expectsJson()) {
            return $this->errors->error($code, $message, $status);
        }

        throw new HttpException($status, $message, $exception);
    }

    /**
     * Laravel 13 renders route-pipeline exceptions before outer middleware can
     * catch them. Register this method with the framework exception handler so
     * package exceptions keep the same HTTP contract on both Laravel versions.
     */
    public function render(Request $request, \Throwable $exception): ?JsonResponse
    {
        return match (true) {
            $exception instanceof AuthenticationRequiredException =>
                $this->errors->error('authentication_required', 'Authentication is required.', 401),
            $exception instanceof AccessDeniedException =>
                $this->errors->error('access_denied', 'Access denied.', 403),
            $exception instanceof ResourceNotFoundException =>
                $this->errors->error('resource_not_found', 'Resource not found.', 404),
            $exception instanceof StorageException => $this->errors->error(
                $this->codeFor($exception),
                $this->messageFor($exception),
                $this->statusFor($exception),
            ),
            default => null,
        };
    }

    private function statusFor(StorageException $exception): int
    {
        return match (true) {
            $exception instanceof InvalidSharePasswordException,
            $exception instanceof ShareDownloadNotAllowedException => 403,
            $exception instanceof ShareExpiredException => 410,
            $exception instanceof ShareDownloadLimitReachedException => 429,
            $exception instanceof MediaQuarantinedException => 423,
            $exception instanceof DirectUploadConflictException => 409,
            $exception instanceof InvalidStorageOperationException => 422,
            default => 400,
        };
    }

    private function codeFor(StorageException $exception): string
    {
        return match (true) {
            $exception instanceof InvalidSharePasswordException => 'invalid_share_password',
            $exception instanceof ShareDownloadNotAllowedException => 'share_download_not_allowed',
            $exception instanceof ShareExpiredException => 'share_expired',
            $exception instanceof ShareDownloadLimitReachedException => 'share_download_limit_reached',
            $exception instanceof MediaQuarantinedException => 'media_quarantined',
            $exception instanceof DirectUploadConflictException => 'direct_upload_conflict',
            $exception instanceof InvalidStorageOperationException => 'invalid_storage_operation',
            default => 'storage_error',
        };
    }

    private function messageFor(StorageException $exception): string
    {
        return match (true) {
            $exception instanceof InvalidSharePasswordException => 'The share credentials are invalid.',
            $exception instanceof ShareDownloadNotAllowedException => 'This share does not allow downloads.',
            $exception instanceof ShareExpiredException => 'This share has expired.',
            $exception instanceof ShareDownloadLimitReachedException => 'This share has reached its download limit.',
            $exception instanceof MediaQuarantinedException => 'This media is not available for delivery.',
            $exception instanceof DirectUploadConflictException => 'The direct upload is in an incompatible state.',
            $exception instanceof InvalidStorageOperationException => 'The storage operation is invalid.',
            default => 'The storage request could not be completed.',
        };
    }
}
