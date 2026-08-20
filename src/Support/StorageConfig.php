<?php

namespace Tetranyble\Storage\Support;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Tetranyble\Storage\Contracts\WorkspaceSubject;
use Tetranyble\Storage\Models\User;
use Tetranyble\Storage\Models\Workspace;

class StorageConfig
{
    public static function workspaceModelClass(): string
    {
        return self::modelClass('workspace', Workspace::class);
    }

    public static function userModelClass(): string
    {
        return self::modelClass('user', User::class);
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

    private static function modelClass(string $key, string $default): string
    {
        $model = config("tetranyble-storage.models.{$key}", $default);
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
}
