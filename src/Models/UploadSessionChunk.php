<?php

namespace Tetranyble\Storage\Models;

use Tetranyble\Storage\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadSessionChunk extends Model
{
    use HasUuid;

    protected $table = 'upload_session_chunks';

    protected $fillable = [
        'upload_session_id',
        'chunk_number',
        'size',
        'checksum',
        'path',
        'uploaded_at',
    ];

    protected $casts = [
        'chunk_number' => 'integer',
        'size' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(UploadSession::class, 'upload_session_id');
    }
}
