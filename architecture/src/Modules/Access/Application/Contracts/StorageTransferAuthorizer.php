<?php

namespace Tetranyble\Storage\Modules\Access\Application\Contracts;

use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;

interface StorageTransferAuthorizer
{
    public function authorizeCopy(
        object $workspace,
        object $source,
        object $destination,
        ?object $actor,
    ): void;

    public function authorizeMove(
        object $workspace,
        object $source,
        object $destination,
        ?object $actor,
    ): void;

    public function authorizeSetDefaultDrive(
        object $workspace,
        object $drive,
        ?object $actor,
    ): void;
}
