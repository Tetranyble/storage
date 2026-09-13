<?php

namespace Tetranyble\Storage\Http\Contracts;

use Illuminate\Http\Request;

interface WorkspaceContext
{
    public function currentWorkspace(Request $request): ?object;

    public function currentActor(Request $request): ?object;

    public function requireWorkspace(Request $request): object;

    public function owns(object $workspace, object $resource): bool;

    public function authorizeOwnership(object $workspace, object $resource): void;
}
