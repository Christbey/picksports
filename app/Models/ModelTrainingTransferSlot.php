<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModelTrainingTransferSlot extends Model
{
    protected $primaryKey = 'worker_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'reserved_bytes' => 'integer'];
    }
}
