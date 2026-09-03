<?php

namespace Tetranyble\Storage\Domain\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\MediaShare;

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

    public function validateDownloadAccess(MediaShare $share, ?string $password = null): void
    {
        $this->validateAccess($share, $password);

        if ($share->access_level !== 'download') {
            abort(403, 'This share does not allow downloads.');
        }
    }

    /**
     * Validate and atomically consume one download slot.
     *
     * The limit check and increment happen in a single UPDATE statement so two
     * concurrent requests cannot both consume the final allowed download.
     */
    public function consumeDownloadAccess(MediaShare $share, ?string $password = null): void
    {
        $this->validateDownloadAccess($share, $password);

        if ($this->consumeDownloadSlot($share, requireDownloadAccess: true)) {
            return;
        }

        // The row changed between validation and consumption. Refresh and surface
        // the actual reason using the same public semantics as normal validation.
        $share->refresh();
        $this->validateDownloadAccess($share, $password);

        throw new RuntimeException('Unable to consume share download slot. Retry the request.');
    }

    /**
     * Backward-compatible counter mutation for callers that already validated the
     * share separately. The download ceiling is still enforced atomically.
     */
    public function incrementDownloads(MediaShare $share): void
    {
        if ($this->consumeDownloadSlot($share)) {
            return;
        }

        $share->refresh();

        if ($share->isExpired()) {
            abort(410, 'Link expired');
        }

        if ($share->hasReachedDownloadsLimit()) {
            abort(429, 'Download limit reached');
        }

        throw new RuntimeException('Unable to increment share download count. Retry the request.');
    }

    public function urlFor(MediaShare $share, bool $absolute = true): string
    {
        $routeName = (string) config('tetranyble-storage.routes.name', 'tetranyble-storage.').'shares.download';

        if (! Route::has($routeName)) {
            throw new RuntimeException("The [{$routeName}] route is not registered. Enable package routes to generate share URLs.");
        }

        return route($routeName, ['token' => $share->token], $absolute);
    }

    private function consumeDownloadSlot(MediaShare $share, bool $requireDownloadAccess = false): bool
    {
        $query = MediaShare::query()
            ->whereKey($share->getKey())
            ->where('requires_password', (bool) $share->requires_password)
            ->when(
                $share->requires_password,
                fn ($query) => $query->where('password_hash', $share->password_hash),
            )
            ->when(
                $requireDownloadAccess,
                fn ($query) => $query->where('access_level', 'download'),
            )
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where(function ($query): void {
                $query->whereNull('max_downloads')
                    ->orWhereColumn('downloads_count', '<', 'max_downloads');
            });

        $updated = $query->increment('downloads_count');

        if ($updated === 1) {
            $share->refresh();

            return true;
        }

        return false;
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
