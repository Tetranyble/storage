<?php
namespace Tetranyble\Storage\Modules\Versioning\Application\Contracts;
interface CurrentMediaSelection { public function select(object $media): object; }
