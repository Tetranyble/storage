<?php

namespace Tetranyble\Storage\Tests\Fixtures\Models;

use Tetranyble\Storage\Concerns\HasMedia;
use Tetranyble\Storage\Concerns\ManipulatesMedia;
use Illuminate\Database\Eloquent\Model;

class DummyMediableModel extends Model
{
    use HasMedia, ManipulatesMedia;

    protected $table = 'dummy_mediable_models';

    protected $guarded = [];
}
