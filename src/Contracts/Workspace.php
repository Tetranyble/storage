<?php

namespace Tetranyble\Storage\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

interface Workspace
{
    public function currentWorkspace(Request $request): ?Model;

    public function currentActor(Request $request): ?Model;

    public function requireWorkspace(Request $request): Model;

    public function owns(Model $workspace, Model $resource): bool;

    public function authorizeOwnership(Model $workspace, Model $resource): void;
}
