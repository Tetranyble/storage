<?php

namespace Tetranyble\Storage\Domain\Media;

use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\MediaShare;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;

class MediaShareService
{
    public function createForMedia(
        Model $workspace,
        Media $media,
        string $accessLevel = 'download',
        ?int $ttlMinutes = 60 * 24 * 7,
        ?int $maxDownloads = null,
        ?string $password = null,
        ?int $createdBy = null,
    ): MediaShare {
        return $this->createShare($workspace, $media, $accessLevel, $ttlMinutes, $maxDownloads, $password, $createdBy);
    }

    public function createForFolder(
        Model $workspace,
        Folder $folder,
        string $accessLevel = 'view',
        ?int $ttlMinutes = 60 * 24 * 7,
        ?int $maxDownloads = null,
        ?string $password = null,
        ?int $createdBy = null,
    ): MediaShare {
        if ((int) $folder->workspace_id !== (int) $workspace->id) {
            abort(404);
        }

        return $this->createShare($workspace, $folder, $accessLevel, $ttlMinutes, $maxDownloads, $password, $createdBy);
    }

    public function resolveByToken(string $token): ?MediaShare
    {
        return MediaShare::where('token', $token)->first();
    }

    public function validateAccess(MediaShare $share, ?string $password = null): void
    {
        if ($share->isExpired()) {
            abort(410, 'Link expired');
        }

        if ($share->hasReachedDownloadsLimit()) {
            abort(429, 'Download limit reached');
        }

        if ($share->requires_password) {
            if (! $password || ! Hash::check($password, $share->password_hash)) {
                abort(403, 'Invalid password');
            }
        }
    }

    public function incrementDownloads(MediaShare $share): void
    {
        $share->increment('downloads_count');
    }

    public function urlFor(MediaShare $share, bool $absolute = true): string
    {
        $routeName = (string) config('tetranyble-storage.routes.name', 'tetranyble-storage.').'shares.download';

        if (! Route::has($routeName)) {
            throw new RuntimeException("The [{$routeName}] route is not registered. Enable package routes to generate share URLs.");
        }

        return route($routeName, ['token' => $share->token], $absolute);
    }

    private function createShare(
        Model $workspace,
        Model $shareable,
        string $accessLevel,
        ?int $ttlMinutes,
        ?int $maxDownloads,
        ?string $password,
        ?int $createdBy,
    ): MediaShare {
        return MediaShare::create([
            'workspace_id' => $workspace->id,
            'shareable_type' => $shareable::class,
            'shareable_id' => $shareable->getKey(),
            'token' => Str::random(32),
            'access_level' => $accessLevel,
            'expires_at' => $ttlMinutes ? now()->addMinutes($ttlMinutes) : null,
            'max_downloads' => $maxDownloads,
            'downloads_count' => 0,
            'requires_password' => $password !== null,
            'password_hash' => $password ? Hash::make($password) : null,
            'created_by' => $createdBy,
        ]);
    }
}
