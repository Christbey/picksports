<?php

namespace App\Models\MLB;

use Database\Factories\MlbPlayerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    /** @use HasFactory<MlbPlayerFactory> */
    use HasFactory;

    protected $table = 'mlb_players';

    protected static function newFactory(): MlbPlayerFactory
    {
        return MlbPlayerFactory::new();
    }

    protected $fillable = [
        'team_id',
        'espn_id',
        'first_name',
        'last_name',
        'full_name',
        'jersey_number',
        'position',
        'batting_hand',
        'throwing_hand',
        'height',
        'weight',
        'hometown',
        'headshot_url',
        'elo_rating',
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
