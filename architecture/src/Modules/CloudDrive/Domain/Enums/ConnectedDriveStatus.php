<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Domain\Enums;

enum ConnectedDriveStatus: string
{
    case CONNECTED    = 'connected';
    case DISCONNECTED = 'disconnected';
    case ERROR        = 'error';
}
