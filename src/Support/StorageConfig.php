<?php

namespace Tetranyble\Storage\Support;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use RuntimeException;
use Tetranyble\Storage\Modules\Workspace\Application\Contracts\StorageUser;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Contracts\WorkspaceSubject;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\User;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;

class StorageConfig
{
    public static function defaultDisk(): Disk
    {
        $configured = config('tetranyble-storage.default_disk') ?: config('filesystems.default', 'local');

        return Disk::default(is_string($configured) ? $configured : null);
    }

    /**
     * Package persistence columns use unsigned BIGINT identifiers for host
     * workspace/user references. Reject UUID/string-key host models early
     * instead of allowing truncation/casts to fail deep inside operations.
     */
    public static function assertHostModelKeyCompatibility(): void
    {
        foreach ([
            'workspace' => self::workspaceModelClass(),
            'user' => self::userModelClass(),
        ] as $key => $modelClass) {
            /** @var Model $model */
            $model = new $modelClass();

            if ($model->getKeyType() !== 'int') {
                throw new LogicException(
                    "The configured storage {$key} model [{$modelClass}] uses key type [{$model->getKeyType()}]. "
                    .'Tetranyble Storage currently requires integer primary keys for host workspace and user models.'
                );
            }
        }
    }

    public static function workspaceModelClass(): string
    {
        return self::modelClass('workspace', Workspace::class);
    }

    /** @return class-string<Model> */
    public static function folderModelClass(): string
    {
        return Folder::class;
    }

    /** @return class-string<Model> */
    public static function mediaModelClass(): string
    {
        return Media::class;
    }

    /** @return class-string<Model> */
    public static function connectedDriveModelClass(): string
    {
        return ConnectedDrive::class;
    }

    public static function userModelClass(): string
    {
        $configured = config('tetranyble-storage.models.user');
        if (is_string($configured) && $configured !== '' && $configured !== User::class) {
            return self::assertModelClass('user', $configured);
        }

        $authModel = self::authUserModelClass();
        if ($authModel !== null) {
            return $authModel;
        }

        if (is_string($configured) && $configured !== '') {
            return self::assertModelClass('user', $configured);
        }

        return User::class;
    }

    public static function workspacesTable(): string
    {
        return self::modelTable('workspaces', self::workspaceModelClass(), 'workspaces');
    }

    public static function usersTable(): string
    {
        return self::modelTable('users', self::userModelClass(), 'users');
    }

    public static function workspaceRelationName(): string
    {
        return (string) config('tetranyble-storage.workspace.workspace_relation', 'workspace');
    }

    public static function actorWorkspaceForeignKey(): string
    {
        return (string) config('tetranyble-storage.workspace.workspace_foreign_key', 'workspace_id');
    }

    public static function resourceWorkspaceForeignKey(): string
    {
        return (string) config('tetranyble-storage.workspace.resource_foreign_key', 'workspace_id');
    }

    public static function findWorkspace(int|string|null $workspaceId): ?Model
    {
        if ($workspaceId === null || $workspaceId === '') {
            return null;
        }

        $workspaceModel = self::workspaceModelClass();

        return $workspaceModel::query()->find($workspaceId);
    }

    public static function findUser(int|string|null $userId): ?Model
    {
        if ($userId === null || $userId === '') {
            return null;
        }

        $userModel = self::userModelClass();

        return $userModel::query()->find($userId);
    }

    public static function resolveWorkspaceFromModel(Model $model): ?Model
    {
        if ($model instanceof WorkspaceSubject) {
            $workspace = $model->getStorageWorkspace();
            if ($workspace instanceof Model) {
                return $workspace;
            }

            return self::findWorkspace($model->getStorageWorkspaceIdentifier());
        }

        $relation = self::workspaceRelationName();
        if ($relation !== '' && method_exists($model, $relation)) {
            $workspace = $model->getRelationValue($relation);
            if ($workspace instanceof Model) {
                return $workspace;
            }
        }

        return self::findWorkspace($model->getAttribute(self::actorWorkspaceForeignKey()));
    }

    public static function actorWorkspaceId(?Model $actor): ?int
    {
        if (! $actor) {
            return null;
        }

        if ($actor instanceof WorkspaceSubject) {
            $identifier = $actor->getStorageWorkspaceIdentifier();
            if ($identifier !== null && $identifier !== '') {
                return (int) $identifier;
            }
        }

        $workspace = self::resolveWorkspaceFromModel($actor);
        if ($workspace instanceof Model) {
            return (int) $workspace->getKey();
        }

        $workspaceId = $actor->getAttribute(self::actorWorkspaceForeignKey());

        return $workspaceId === null || $workspaceId === ''
            ? null
            : (int) $workspaceId;
    }

    public static function actorIdentifier(?Model $actor): int|string|null
    {
        if (! $actor) {
            return null;
        }

        if ($actor instanceof StorageUser) {
            return $actor->getStorageUserIdentifier();
        }

        return method_exists($actor, 'getKey') ? $actor->getKey() : null;
    }

    private static function modelClass(string $key, string $default): string
    {
        $model = config("tetranyble-storage.models.{$key}", $default);
        return self::assertModelClass($key, $model);
    }

    private static function assertModelClass(string $key, mixed $model): string
    {
        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            throw new RuntimeException("The configured storage {$key} model must be an Eloquent model.");
        }

        return $model;
    }

    private static function modelTable(string $key, string $modelClass, string $fallback): string
    {
        $configured = config("tetranyble-storage.database.tables.{$key}");
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        /** @var Model $model */
        $model = new $modelClass();
        $table = $model->getTable();

        return is_string($table) && $table !== '' ? $table : $fallback;
    }

    private static function authUserModelClass(): ?string
    {
        $guard = config('tetranyble-storage.workspace.guard');
        if (! is_string($guard) || $guard === '') {
            $guard = (string) config('auth.defaults.guard', '');
        }
        $provider = null;

        if ($guard !== '') {
            $provider = config("auth.guards.{$guard}.provider");
        }

        if (! is_string($provider) || $provider === '') {
            $provider = config('auth.defaults.provider');
        }

        if (! is_string($provider) || $provider === '') {
            return null;
        }

        $model = config("auth.providers.{$provider}.model");
        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            return null;
        }

        return $model;
    }
}
