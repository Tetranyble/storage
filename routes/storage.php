<?php

use Illuminate\Support\Facades\Route;
use Tetranyble\Storage\Http\Controllers\ChunkedMediaUploadController;
use Tetranyble\Storage\Http\Controllers\DownloadController;
use Tetranyble\Storage\Http\Controllers\DirectUploadController;
use Tetranyble\Storage\Http\Controllers\MediaController;
use Tetranyble\Storage\Http\Controllers\MediaLibraryController;
use Tetranyble\Storage\Http\Controllers\BulkMediaController;
use Tetranyble\Storage\Http\Controllers\MediaShareController;
use Tetranyble\Storage\Http\Controllers\MediaTransferController;
use Tetranyble\Storage\Http\Middleware\AddStorageSecurityHeaders;
use Tetranyble\Storage\Http\Middleware\HandleStorageExceptions;

$routeConfig = config('tetranyble-storage.routes', []);

// Keep published route files subject to the same security switch as auto-loaded routes.
if (! ($routeConfig['enabled'] ?? true)) {
    return;
}

$controllers = array_replace([
    'download' => DownloadController::class,
    'media' => MediaController::class,
    'chunked_upload' => ChunkedMediaUploadController::class,
    'direct_upload' => DirectUploadController::class,
    'library' => MediaLibraryController::class,
    'bulk' => BulkMediaController::class,
    'share' => MediaShareController::class,
    'transfer' => MediaTransferController::class,
], $routeConfig['controllers'] ?? []);
$name = (string) ($routeConfig['name'] ?? 'tetranyble-storage.');
$rateLimits = $routeConfig['rate_limits'] ?? [];
$rateLimitsEnabled = (bool) ($rateLimits['enabled'] ?? true);
$boundaryMiddleware = [HandleStorageExceptions::class, AddStorageSecurityHeaders::class];
$publicMiddleware = array_merge(
    $boundaryMiddleware,
    $routeConfig['public_middleware'] ?? ['web'],
    $rateLimitsEnabled ? ['throttle:tetranyble-storage-public'] : [],
);
$protectedMiddleware = array_merge(
    $boundaryMiddleware,
    $routeConfig['middleware'] ?? ['web', 'auth'],
    $rateLimitsEnabled ? ['throttle:tetranyble-storage-authenticated'] : [],
);

Route::middleware($publicMiddleware)
    ->prefix($routeConfig['prefix'] ?? 'storage')
    ->name($name)
    ->group(function () use ($controllers): void {
        Route::match(['get', 'post'], 'shares/{token}', [$controllers['share'], 'download'])
            ->name('shares.download');
    });

Route::middleware($protectedMiddleware)
    ->prefix($routeConfig['prefix'] ?? 'storage')
    ->name($name)
    ->group(function () use ($controllers): void {
        Route::get('media/{media}/download', [$controllers['download'], 'show'])
            ->name('media.download');
        Route::post('media/zip', [$controllers['download'], 'zip'])
            ->name('media.zip');

        Route::post('media', [$controllers['media'], 'store'])
            ->name('media.store');
        Route::post('media/import-url', [$controllers['media'], 'importUrl'])
            ->name('media.import-url');
        Route::get('media/{media}', [$controllers['media'], 'show'])
            ->name('media.show');
        Route::match(['put', 'patch'], 'media/{media}', [$controllers['media'], 'update'])
            ->name('media.update');
        Route::delete('media/{media}', [$controllers['media'], 'destroy'])
            ->name('media.destroy');
        Route::post('media/{media}/current', [$controllers['media'], 'setCurrent'])
            ->name('media.current');
        Route::post('media/{media}/copy-storage', [$controllers['transfer'], 'copyMedia'])
            ->name('media.storage.copy');
        Route::post('media/{media}/move-storage', [$controllers['transfer'], 'moveMedia'])
            ->name('media.storage.move');

        Route::post('drives/{drive}/default', [$controllers['transfer'], 'setDefaultDrive'])
            ->name('drives.default');
        Route::post('drives/{drive}/files/copy', [$controllers['transfer'], 'copyDriveFile'])
            ->name('drives.files.copy');
        Route::post('drives/{drive}/files/move', [$controllers['transfer'], 'moveDriveFile'])
            ->name('drives.files.move');

        Route::post('uploads', [$controllers['chunked_upload'], 'store'])
            ->name('uploads.store');
        Route::get('uploads/{uploadSession}', [$controllers['chunked_upload'], 'show'])
            ->name('uploads.show');
        Route::put('uploads/{uploadSession}/chunks/{chunk}', [$controllers['chunked_upload'], 'update'])
            ->whereNumber('chunk')
            ->name('uploads.chunks.update');
        Route::post('uploads/{uploadSession}/finalize', [$controllers['chunked_upload'], 'finalize'])
            ->name('uploads.finalize');
        Route::delete('uploads/{uploadSession}', [$controllers['chunked_upload'], 'destroy'])
            ->name('uploads.destroy');

        Route::post('direct-uploads', [$controllers['direct_upload'], 'store'])
            ->name('direct-uploads.store');
        Route::get('direct-uploads/{directUpload}', [$controllers['direct_upload'], 'show'])
            ->name('direct-uploads.show');
        Route::post('direct-uploads/{directUpload}/sign', [$controllers['direct_upload'], 'refresh'])
            ->name('direct-uploads.sign');
        Route::post('direct-uploads/{directUpload}/finalize', [$controllers['direct_upload'], 'finalize'])
            ->name('direct-uploads.finalize');
        Route::delete('direct-uploads/{directUpload}', [$controllers['direct_upload'], 'destroy'])
            ->name('direct-uploads.destroy');

        Route::prefix('library')->name('library.')->group(function () use ($controllers): void {
            Route::get('/', [$controllers['library'], 'index'])->name('index');
            Route::get('search', [$controllers['library'], 'search'])->name('search');
            Route::get('recent', [$controllers['library'], 'recent'])->name('recent');
            Route::get('activity', [$controllers['library'], 'activity'])->name('activity');
            Route::get('usage', [$controllers['library'], 'usage'])->name('usage');
            Route::get('trash', [$controllers['library'], 'trash'])->name('trash');
            Route::post('trash/empty', [$controllers['library'], 'emptyTrash'])->name('trash.empty');
            Route::post('upload', [$controllers['library'], 'upload'])->name('upload');
            Route::prefix('bulk')->name('bulk.')->group(function () use ($controllers): void {
                Route::post('trash', [$controllers['bulk'], 'trash'])->name('trash');
                Route::post('restore', [$controllers['bulk'], 'restore'])->name('restore');
                Route::post('delete', [$controllers['bulk'], 'delete'])->name('delete');
                Route::post('move', [$controllers['bulk'], 'move'])->name('move');
            });
            Route::post('folders', [$controllers['library'], 'createFolder'])->name('folders.store');
            Route::post('folders/{folder}/archive', [$controllers['library'], 'archiveFolder'])->name('folders.archive');
            Route::post('folders/{folder}/unarchive', [$controllers['library'], 'unarchiveFolder'])->name('folders.unarchive');
            Route::delete('{media}', [$controllers['library'], 'destroy'])->name('destroy');
            Route::post('{media}/restore', [$controllers['library'], 'restore'])->name('restore');
            Route::delete('{media}/force-delete', [$controllers['library'], 'forceDelete'])->name('force-delete');
            Route::post('{media}/move', [$controllers['library'], 'move'])->name('move');
            Route::post('{media}/rename', [$controllers['library'], 'rename'])->name('rename');
            Route::post('{media}/shares', [$controllers['library'], 'createShare'])->name('shares.store');
            Route::delete('{media}/shares/{share}', [$controllers['library'], 'revokeShare'])->name('shares.destroy');
        });
    });
