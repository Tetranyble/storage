<?php

namespace Tetranyble\Storage\Modules\Transfer\Application;

use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Application\Contracts\StorageTransferAuthorizer;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;

class AccessControlTransferAuthorizer implements StorageTransferAuthorizer
{
    public function __construct(private readonly ResourceAccessControl $access) {}

    public function authorizeCopy(
        object $workspace,
        object $source,
        object $destination,
        ?object $actor,
    ): void {
        $this->access->authorizeView($workspace, $source, $actor);

        if (! $destination instanceof Disk) {
            $this->access->authorizeEdit($workspace, $destination, $actor);
        }
    }

    public function authorizeMove(
        object $workspace,
        object $source,
        object $destination,
        ?object $actor,
    ): void {
        $this->access->authorizeEdit($workspace, $source, $actor);

        if (! $destination instanceof Disk) {
            $this->access->authorizeEdit($workspace, $destination, $actor);
        }
    }

    public function authorizeSetDefaultDrive(
        object $workspace,
        object $drive,
        ?object $actor,
    ): void {
        $this->access->authorizeEdit($workspace, $drive, $actor);
    }
}
