<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResearchSource extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['allowed_hosts' => 'array', 'enabled' => 'boolean', 'checked_at' => 'immutable_datetime', 'succeeded_at' => 'immutable_datetime'];
    }
}
