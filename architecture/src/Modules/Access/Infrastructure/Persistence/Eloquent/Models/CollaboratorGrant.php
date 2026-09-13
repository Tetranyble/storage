<?php

namespace Tetranyble\Storage\Modules\Access\Infrastructure\Persistence\Eloquent\Models;

use Tetranyble\Storage\Modules\Access\Domain\Enums\CollaboratorRole;
use Tetranyble\Storage\Modules\Shared\Infrastructure\Persistence\Eloquent\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Eloquent attributes exposed by this package model.
 * @property mixed $collaboratable_id
 * @property mixed $role
 */
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
