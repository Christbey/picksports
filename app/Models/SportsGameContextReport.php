<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SportsGameContextReport extends Model
{
    protected $fillable = [
        'sport',
        'game_id',
        'game_date',
        'status',
        'provider',
        'model',
        'prompt_version',
        'input_hash',
        'confidence',
        'summary',
        'team_context',
        'situational_context',
        'market_snapshot',
        'facts',
        'sources',
        'risk_flags',
        'raw_payload',
        'researched_at',
        'expires_at',
        'latency_ms',
    ];

    protected function casts(): array
    {
        return [
            'game_id' => 'integer',
            'game_date' => 'date',
            'confidence' => 'integer',
            'team_context' => 'array',
            'situational_context' => 'array',
            'market_snapshot' => 'array',
            'facts' => 'array',
            'sources' => 'array',
            'risk_flags' => 'array',
            'raw_payload' => 'array',
            'researched_at' => 'datetime',
            'expires_at' => 'datetime',
            'latency_ms' => 'integer',
        ];
    }
}
