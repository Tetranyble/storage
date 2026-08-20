<?php

namespace Tetranyble\Storage\Models;

use Tetranyble\Storage\Enums\CollaboratorRole;
use Tetranyble\Storage\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CollaboratorGrant extends Model
{
    use HasUuid;

    protected $table = 'collaborator_grants';

    protected $fillable = [
        'workspace_id',
        'collaboratable_type',
        'collaboratable_id',
        'user_id',
        'role',
        'granted_by',
    ];

    protected $casts = [
        'role' => CollaboratorRole::class,
    ];

    public function collaboratable(): MorphTo
    {
        return $this->morphTo();
    }
}
