<?php

namespace Tetranyble\Storage\Events;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Models\ConnectedDrive;

final class DriveConnected
{
    public function __construct(
        public readonly ConnectedDrive $drive,
        public readonly ?Model $actor,
    ) {}
}
