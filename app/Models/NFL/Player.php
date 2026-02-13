<?php

namespace App\Models\NFL;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    /** @use HasFactory<\Database\Factories\NflPlayerFactory> */
    use HasFactory;

    protected $table = 'nfl_players';

    protected $fillable = [
        'team_id',
        'espn_id',
        'first_name',
        'last_name',
        'full_name',
        'jersey_number',
        'position',
        'height',
        'weight',
        'age',
        'experience',
        'college',
        'status',
        'headshot_url',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function playerStats(): HasMany
    {
        return $this->hasMany(PlayerStat::class, 'player_id');
    }

    protected static function newFactory(): \Database\Factories\NflPlayerFactory
    {
        return \Database\Factories\NflPlayerFactory::new();
    }
}
