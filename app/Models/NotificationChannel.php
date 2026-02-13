<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationChannel extends Model
{
    /** @use HasFactory<\Database\Factories\NotificationChannelFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'channel',
        'enabled',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'config' => 'array',
        ];
    }
}
