<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Healthcheck extends Model
{
    protected $fillable = [
        'sport',
        'check_type',
        'status',
        'message',
        'metadata',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'checked_at' => 'datetime',
        ];
    }
}
