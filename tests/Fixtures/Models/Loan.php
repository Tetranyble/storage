<?php

namespace Tetranyble\Storage\Tests\Fixtures\Models;

use Tetranyble\Storage\Concerns\HasMedia;
use Tetranyble\Storage\Concerns\ManipulatesMedia;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Loan extends Model
{
    use HasMedia, ManipulatesMedia;

    protected $table = 'loans';

    protected $guarded = [];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function mediaBaseDirectory(): string
    {
        return 'loans';
    }
}
