<?php

namespace Tetranyble\Storage\Modules\Sharing\Infrastructure\Application;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;
use Tetranyble\Storage\Modules\Sharing\Application\Contracts\ShareUrlGenerator;
use Tetranyble\Storage\Modules\Sharing\Domain\Enums\ShareAccessLevel;
use Tetranyble\Storage\Modules\Sharing\Domain\Exceptions\InvalidSharePasswordException;
use Tetranyble\Storage\Modules\Sharing\Domain\Policy\ShareAccessPolicy;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Persistence\Eloquent\Models\MediaShare;

class MediaShareService
{
    public function __construct(private readonly ShareUrlGenerator $urls) {}

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
        if ((int) $folder->workspace_id !== (int) $workspace->getKey()) {
            throw new ResourceNotFoundException;
        }

        return $this->createShare($workspace, $folder, $accessLevel, $ttlMinutes, $maxDownloads, $password, $createdBy);
    }

    public function resolveByToken(string $token): ?MediaShare
    {
        return MediaShare::where('token', $token)->first();
    }

    public function validateAccess(MediaShare $share, ?string $password = null): void
    {
        $this->policy($share)->assertAccessible(now()->toDateTimeImmutable());

        if ($share->requires_password) {
            if (! $password || ! Hash::check($password, $share->password_hash)) {
                throw new InvalidSharePasswordException;
            }
        }
    }

    public function validateDownloadAccess(MediaShare $share, ?string $password = null): void
    {
        $this->validateAccess($share, $password);

        $this->policy($share)->assertDownloadAllowed(now()->toDateTimeImmutable());
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

    public function urlFor(MediaShare $share, bool $absolute = true): string
    {
        return $this->urls->urlFor($share, $absolute);
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

    private function policy(MediaShare $share): ShareAccessPolicy
    {
        $accessLevel = ShareAccessLevel::tryFrom((string) $share->access_level);
        if (! $accessLevel instanceof ShareAccessLevel) {
            throw new RuntimeException(sprintf(
                'Share [%s] has unsupported access level [%s].',
                (string) $share->getKey(),
                (string) $share->access_level,
            ));
        }

        return new ShareAccessPolicy(
            accessLevel: $accessLevel,
            expiresAt: $share->expires_at?->toDateTimeImmutable(),
            maxDownloads: $share->max_downloads === null ? null : (int) $share->max_downloads,
            downloadsCount: (int) $share->downloads_count,
            requiresPassword: (bool) $share->requires_password,
        );
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
        $level = ShareAccessLevel::tryFrom($accessLevel);
        if (! $level instanceof ShareAccessLevel) {
            throw new InvalidArgumentException(sprintf('Unsupported share access level [%s].', $accessLevel));
        }

        return MediaShare::create([
            'workspace_id' => $workspace->getKey(),
            'shareable_type' => $shareable->getMorphClass(),
            'shareable_id' => $shareable->getKey(),
            'token' => Str::random(32),
            'access_level' => $level->value,
            'expires_at' => $ttlMinutes ? now()->addMinutes($ttlMinutes) : null,
            'max_downloads' => $maxDownloads,
            'downloads_count' => 0,
            'requires_password' => $password !== null,
            'password_hash' => $password ? Hash::make($password) : null,
            'created_by' => $createdBy,
        ]);
    }
}
