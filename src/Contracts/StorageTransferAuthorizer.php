<?php

namespace Tetranyble\Storage\Contracts;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Models\ConnectedDrive;

interface StorageTransferAuthorizer
{
    public function authorizeCopy(
        Model $workspace,
        Model $source,
        Model|Disk $destination,
        ?Model $actor,
    ): void;

    public function authorizeMove(
        Model $workspace,
        Model $source,
        Model|Disk $destination,
        ?Model $actor,
    ): void;

    public function authorizeSetDefaultDrive(
        Model $workspace,
        ConnectedDrive $drive,
        ?Model $actor,
    ): void;
}
