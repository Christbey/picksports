<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $fillable = [
        'type', 'recipient_key', 'channel', 'deduplication_key', 'status',
        'source_type', 'source_id', 'reason_code', 'dispatched_at', 'accepted_at',
        'failed_at', 'suppressed_at',
    ];

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime', 'accepted_at' => 'datetime',
            'failed_at' => 'datetime', 'suppressed_at' => 'datetime',
        ];
    }
}
