<?php

namespace Tetranyble\Storage\Enums;

enum ConnectedDriveStatus: string
{
    case CONNECTED    = 'connected';
    case DISCONNECTED = 'disconnected';
    case ERROR        = 'error';
}
