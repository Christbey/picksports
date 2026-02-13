<?php

namespace App\Models\CFB;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    /** @use HasFactory<\Database\Factories\CfbPlayerFactory> */
    use HasFactory;

    protected $table = 'cfb_players';

    protected $fillable = [
        'team_id',
        'espn_id',
        'name',
        'display_name',
        'short_name',
        'jersey',
        'position',
        'height',
        'weight',
        'experience',
        'college',
        'headshot',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function playerStats(): HasMany
    {
        return $this->hasMany(PlayerStat::class, 'player_id');
    }
}
