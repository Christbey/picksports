<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModelTrainingWorker extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['sports' => 'array', 'abilities' => 'array', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
