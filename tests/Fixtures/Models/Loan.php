<?php

namespace Tetranyble\Storage\Tests\Fixtures\Models;

use Tetranyble\Storage\Contracts\Mediable;
use Tetranyble\Storage\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Loan extends Model
{
    use Mediable;

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
