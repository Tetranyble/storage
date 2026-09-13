<?php

namespace Tetranyble\Storage\Modules\Folder\Application;

use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaLibrary;

/**
 * Canonical application boundary for workspace-wide trash cleanup.
 *
 * This preserves the package's existing workspace-wide empty-trash semantics;
 * hosts requiring administrator-only cleanup should protect the route/policy at
 * their workspace authorization boundary.
 */
final class EmptyTrash
{
    public function __construct(private readonly MediaLibrary $library) {}

    public function handle(object $workspace): void
    {
        $this->library->emptyTrash($workspace);
    }
}
