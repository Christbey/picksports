<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommandHeartbeat extends Model
{
    protected $fillable = [
        'sport',
        'command',
        'status',
        'source',
        'error',
        'metadata',
        'ran_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'ran_at' => 'datetime',
        ];
    }
}
