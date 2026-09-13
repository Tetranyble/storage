<?php

namespace Tetranyble\Storage\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Concerns\HasMedia;
use Tetranyble\Storage\Concerns\ManipulatesMedia;

class DummyMediableModel extends Model
{
    use HasMedia, ManipulatesMedia;

    protected $table = 'dummy_mediable_models';

    protected $guarded = [];
}
