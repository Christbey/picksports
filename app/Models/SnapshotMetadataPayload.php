<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SnapshotMetadataPayload extends Model
{
    protected $primaryKey = 'sha256';

    protected $hidden = ['payload'];

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = ['sha256', 'compressed_sha256', 'json_bytes', 'compressed_bytes', 'payload'];

    protected function casts(): array
    {
        return ['json_bytes' => 'integer', 'compressed_bytes' => 'integer'];
    }
}
