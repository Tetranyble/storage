<?php

namespace Tetranyble\Storage\Enums;

enum MediaRevisionEventType: string
{
    case CREATED = 'created';
    case REVISION_UPLOADED = 'revision_uploaded';
    case REVISION_RESTORED = 'revision_restored';
    case ATTACHED_EXISTING = 'attached_existing';
    case EXTERNAL_ATTACHED = 'external_attached';
    case DELETED = 'deleted';
}
