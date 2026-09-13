<?php

namespace Tetranyble\Storage\Modules\Access\Infrastructure\Persistence\Eloquent\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Modules\Access\Domain\Enums\AccessScope;
use Tetranyble\Storage\Modules\Access\Domain\Enums\CollaboratorRole;
use Tetranyble\Storage\Modules\Access\Infrastructure\Persistence\Eloquent\Models\CollaboratorGrant;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Support\StorageConfig;

/**
 * Applies resource visibility before count/limit/offset are evaluated.
 *
 * Folder hierarchy state and actor grants are loaded once per workspace/actor for
 * the lifetime of this service instance. Media rows themselves are never loaded
 * to determine visibility; the resulting folder/grant state is expressed as SQL
 * predicates on the caller's query.
 */
final class ResourceVisibilityQuery
{
    /** @var array<string, array<string, mixed>> */
    private array $snapshots = [];

    public function forget(Model $workspace, ?Model $actor): void
    {
        if (! $actor) {
            return;
        }

        unset($this->snapshots[$workspace->getKey().'|'.$actor->getKey()]);
    }

    /**
     * @param  Builder<Folder>  $query
     * @return Builder<Folder>
     */
    public function folders(Builder $query, Model $workspace, ?Model $actor): Builder
    {
        // Preserve the package's existing trusted/internal query behaviour when
        // no actor is supplied. Public HTTP surfaces provide an actor.
        if (! $actor) {
            return $query;
        }

        $snapshot = $this->snapshot($workspace, $actor);
        $folderMorph = (new Folder)->getMorphClass();

        return $query->where(function (Builder $visible) use ($workspace, $actor, $snapshot, $folderMorph): void {
            $visible->where('folders.created_by', $actor->getKey())
                ->orWhereExists(function ($grant) use ($workspace, $actor, $folderMorph): void {
                    $grant->selectRaw('1')
                        ->from('collaborator_grants')
                        ->whereColumn('collaborator_grants.collaboratable_id', 'folders.id')
                        ->where('collaborator_grants.collaboratable_type', $folderMorph)
                        ->where('collaborator_grants.workspace_id', $workspace->getKey())
                        ->where('collaborator_grants.user_id', $actor->getKey());
                });

            if ($snapshot['workspace_matches']) {
                $visible->orWhere(function (Builder $workspaceVisible) use ($snapshot): void {
                    $workspaceVisible->where('folders.access_scope', AccessScope::WORKSPACE->value);

                    if ($snapshot['restricted_boundary_folder_ids'] !== []) {
                        $workspaceVisible->whereIntegerNotInRaw('folders.id', $snapshot['restricted_boundary_folder_ids']);
                    }
                });
            }
        });
    }

    /**
     * @param  Builder<Media>  $query
     * @return Builder<Media>
     */
    public function media(Builder $query, Model $workspace, ?Model $actor): Builder
    {
        if (! $actor) {
            return $query;
        }

        $snapshot = $this->snapshot($workspace, $actor);
        $mediaMorph = (new Media)->getMorphClass();

        return $query->where(function (Builder $visible) use ($workspace, $actor, $snapshot, $mediaMorph): void {
            $visible->where('media.uploaded_by', $actor->getKey())
                ->orWhereExists(function ($grant) use ($workspace, $actor, $mediaMorph): void {
                    $grant->selectRaw('1')
                        ->from('collaborator_grants')
                        ->whereColumn('collaborator_grants.collaboratable_id', 'media.id')
                        ->where('collaborator_grants.collaboratable_type', $mediaMorph)
                        ->where('collaborator_grants.workspace_id', $workspace->getKey())
                        ->where('collaborator_grants.user_id', $actor->getKey());
                });

            if ($snapshot['media_folder_access_ids'] !== []) {
                $visible->orWhereIntegerInRaw('media.folder_id', $snapshot['media_folder_access_ids']);
            }

            if ($snapshot['workspace_matches']) {
                $visible->orWhere(function (Builder $workspaceVisible) use ($snapshot): void {
                    $workspaceVisible->where('media.access_scope', AccessScope::WORKSPACE->value)
                        ->where(function (Builder $withoutRestrictedBoundary) use ($snapshot): void {
                            $withoutRestrictedBoundary->whereNull('media.folder_id');

                            if ($snapshot['restricted_boundary_folder_ids'] === []) {
                                $withoutRestrictedBoundary->orWhereNotNull('media.folder_id');
                            } else {
                                $withoutRestrictedBoundary->orWhereIntegerNotInRaw(
                                    'media.folder_id',
                                    $snapshot['restricted_boundary_folder_ids'],
                                );
                            }
                        });
                });
            }
        });
    }

    /** @return Builder<Folder> */
    public function visibleFolders(Model $workspace, ?Model $actor): Builder
    {
        return $this->folders(
            Folder::query()->where('folders.workspace_id', $workspace->getKey()),
            $workspace,
            $actor,
        );
    }

    /** @return Builder<Media> */
    public function visibleMedia(Model $workspace, ?Model $actor): Builder
    {
        return $this->media(
            Media::query()->where('media.workspace_id', $workspace->getKey()),
            $workspace,
            $actor,
        );
    }

    public function effectiveFolderRole(Model $workspace, Folder $folder, ?Model $actor): ?CollaboratorRole
    {
        if (! $actor) {
            return null;
        }

        $snapshot = $this->snapshot($workspace, $actor);
        $role = $snapshot['folder_effective_roles'][(int) $folder->getKey()] ?? null;

        return is_string($role) ? CollaboratorRole::tryFrom($role) : null;
    }

    /** @return array<string, mixed> */
    private function snapshot(Model $workspace, Model $actor): array
    {
        $key = $workspace->getKey().'|'.$actor->getKey();

        if (isset($this->snapshots[$key])) {
            return $this->snapshots[$key];
        }

        $folders = Folder::query()
            ->where('workspace_id', $workspace->getKey())
            ->get(['id', 'parent_id', 'created_by', 'access_scope']);

        $folderMorph = (new Folder)->getMorphClass();
        $grantRoles = CollaboratorGrant::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $actor->getKey())
            ->where('collaboratable_type', $folderMorph)
            ->get(['collaboratable_id', 'role'])
            ->mapWithKeys(function (CollaboratorGrant $grant): array {
                $role = $grant->role instanceof CollaboratorRole
                    ? $grant->role
                    : CollaboratorRole::tryFrom((string) $grant->getRawOriginal('role'));

                return [(int) $grant->collaboratable_id => $role?->value];
            })
            ->all();

        $nodes = [];
        foreach ($folders as $folder) {
            $nodes[(int) $folder->id] = [
                'parent_id' => $folder->parent_id !== null ? (int) $folder->parent_id : null,
                'created_by' => $folder->created_by !== null ? (int) $folder->created_by : null,
                'scope' => $this->scopeValue($folder->access_scope),
            ];
        }

        $workspaceMatches = StorageConfig::actorWorkspaceId($actor) === (int) $workspace->getKey();
        $restrictedMemo = [];
        $restrictedBoundaryIds = [];
        $mediaFolderAccessIds = [];
        $folderEffectiveRoles = [];

        foreach (array_keys($nodes) as $folderId) {
            if ($this->hasRestrictedBoundary($folderId, $nodes, $restrictedMemo)) {
                $restrictedBoundaryIds[] = $folderId;
            }

            $directRole = $this->directFolderRole(
                $folderId,
                $nodes,
                $grantRoles,
                (int) $actor->getKey(),
                $workspaceMatches,
                $restrictedMemo,
            );
            if ($directRole) {
                $folderEffectiveRoles[$folderId] = $directRole->value;
            }

            if ($this->folderChainRole(
                $folderId,
                $nodes,
                $grantRoles,
                (int) $actor->getKey(),
                $workspaceMatches,
            )) {
                $mediaFolderAccessIds[] = $folderId;
            }
        }

        return $this->snapshots[$key] = [
            'workspace_matches' => $workspaceMatches,
            'restricted_boundary_folder_ids' => array_values(array_unique($restrictedBoundaryIds)),
            'media_folder_access_ids' => array_values(array_unique($mediaFolderAccessIds)),
            'folder_effective_roles' => $folderEffectiveRoles,
        ];
    }

    /**
     * Folder visibility intentionally mirrors AccessControlService::effectiveRole()
     * for Folder itself: owner/direct grant plus workspace fallback only when no
     * restricted folder exists in the folder's ancestor chain.
     *
     * @param  array<int, array{parent_id:?int,created_by:?int,scope:string}>  $nodes
     * @param  array<int, string|null>  $grantRoles
     * @param  array<int, bool>  $restrictedMemo
     */
    private function directFolderRole(
        int $folderId,
        array $nodes,
        array $grantRoles,
        int $actorId,
        bool $workspaceMatches,
        array &$restrictedMemo,
    ): ?CollaboratorRole {
        $node = $nodes[$folderId] ?? null;
        if (! $node) {
            return null;
        }

        $role = $node['created_by'] === $actorId ? CollaboratorRole::OWNER : null;
        $grantRole = isset($grantRoles[$folderId]) && is_string($grantRoles[$folderId])
            ? CollaboratorRole::tryFrom($grantRoles[$folderId])
            : null;
        $role = CollaboratorRole::highest($role, $grantRole);

        if ($workspaceMatches
            && $node['scope'] === AccessScope::WORKSPACE->value
            && ! $this->hasRestrictedBoundary($folderId, $nodes, $restrictedMemo)) {
            $role = CollaboratorRole::highest($role, CollaboratorRole::EDITOR);
        }

        return $role;
    }

    /**
     * Mirrors AccessControlService::folderRoleChain() for Media inheritance.
     *
     * @param  array<int, array{parent_id:?int,created_by:?int,scope:string}>  $nodes
     * @param  array<int, string|null>  $grantRoles
     */
    private function folderChainRole(
        int $folderId,
        array $nodes,
        array $grantRoles,
        int $actorId,
        bool $workspaceMatches,
    ): ?CollaboratorRole {
        $role = null;
        $cursor = $folderId;
        $workspaceFallbackBlocked = false;
        $visited = [];

        while (isset($nodes[$cursor]) && ! isset($visited[$cursor])) {
            $visited[$cursor] = true;
            $node = $nodes[$cursor];

            $grantRole = isset($grantRoles[$cursor]) && is_string($grantRoles[$cursor])
                ? CollaboratorRole::tryFrom($grantRoles[$cursor])
                : null;
            $role = CollaboratorRole::highest($role, $grantRole);

            if ($node['created_by'] === $actorId) {
                $role = CollaboratorRole::highest($role, CollaboratorRole::OWNER);
            }

            if ($node['scope'] === AccessScope::RESTRICTED->value) {
                $workspaceFallbackBlocked = true;
            }

            if (! $workspaceFallbackBlocked
                && $workspaceMatches
                && $node['scope'] === AccessScope::WORKSPACE->value) {
                $role = CollaboratorRole::highest($role, CollaboratorRole::EDITOR);
            }

            $cursor = $node['parent_id'] ?? 0;
        }

        return $role;
    }

    /**
     * @param  array<int, array{parent_id:?int,created_by:?int,scope:string}>  $nodes
     * @param  array<int, bool>  $memo
     */
    private function hasRestrictedBoundary(int $folderId, array $nodes, array &$memo): bool
    {
        if (array_key_exists($folderId, $memo)) {
            return $memo[$folderId];
        }

        $cursor = $folderId;
        $visited = [];

        while (isset($nodes[$cursor]) && ! isset($visited[$cursor])) {
            if (array_key_exists($cursor, $memo)) {
                return $memo[$folderId] = $memo[$cursor];
            }

            $visited[$cursor] = true;
            $node = $nodes[$cursor];

            if ($node['scope'] === AccessScope::RESTRICTED->value) {
                return $memo[$folderId] = true;
            }

            $cursor = $node['parent_id'] ?? 0;
        }

        return $memo[$folderId] = false;
    }

    private function scopeValue(mixed $scope): string
    {
        if ($scope instanceof AccessScope) {
            return $scope->value;
        }

        return is_string($scope) ? $scope : AccessScope::default()->value;
    }
}
