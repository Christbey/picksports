<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class CommandHeartbeat extends Model
{
    use MassPrunable;

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

    public function prunable(): Builder
    {
        $retentionDays = max(1, (int) config('retention.command_heartbeats_days', 90));

        return static::query()->where('ran_at', '<=', now()->subDays($retentionDays));
    }
}
