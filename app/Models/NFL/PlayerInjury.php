<?php

namespace App\Models\NFL;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerInjury extends Model
{
    use HasFactory;

    protected $table = 'nfl_player_injuries';

    protected $fillable = [
        'player_id',
        'team_id',
        'injury_key',
        'espn_injury_id',
        'status',
        'detail',
        'type',
        'injury_date',
        'return_date',
        'source_updated_at',
        'is_active',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'injury_date' => 'date',
            'return_date' => 'date',
            'source_updated_at' => 'datetime',
            'is_active' => 'boolean',
            'raw_payload' => 'array',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
