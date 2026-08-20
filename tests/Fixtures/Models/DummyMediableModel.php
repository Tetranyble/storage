<?php

namespace Tetranyble\Storage\Tests\Fixtures\Models;

use Tetranyble\Storage\Contracts\Mediable;
use Illuminate\Database\Eloquent\Model;

class DummyMediableModel extends Model
{
    use Mediable;

    protected $table = 'dummy_mediable_models';

    protected $guarded = [];
}
