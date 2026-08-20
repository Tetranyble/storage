<?php

namespace Tetranyble\Storage\Domain\Media;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Contracts\StorageTransferAuthorizer;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Models\ConnectedDrive;

class AccessControlTransferAuthorizer implements StorageTransferAuthorizer
{
    public function __construct(private readonly ResourceAccessControl $access) {}

    public function authorizeCopy(
        Model $workspace,
        Model $source,
        Model|Disk $destination,
        ?Model $actor,
    ): void {
        $this->access->authorizeView($workspace, $source, $actor);

        if ($destination instanceof Model) {
            $this->access->authorizeEdit($workspace, $destination, $actor);
        }
    }

    public function authorizeMove(
        Model $workspace,
        Model $source,
        Model|Disk $destination,
        ?Model $actor,
    ): void {
        $this->access->authorizeEdit($workspace, $source, $actor);

        if ($destination instanceof Model) {
            $this->access->authorizeEdit($workspace, $destination, $actor);
        }
    }

    public function authorizeSetDefaultDrive(
        Model $workspace,
        ConnectedDrive $drive,
        ?Model $actor,
    ): void {
        $this->access->authorizeEdit($workspace, $drive, $actor);
    }
}
