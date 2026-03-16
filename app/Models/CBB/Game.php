<?php

namespace App\Models\CBB;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Game extends Model
{
    /** @use HasFactory<\Database\Factories\CbbGameFactory> */
    use HasFactory;

    protected $table = 'cbb_games';

    protected static function newFactory(): \Database\Factories\CbbGameFactory
    {
        return \Database\Factories\CbbGameFactory::new();
    }

    protected $fillable = [
        'espn_event_id',
        'espn_uid',
        'home_team_id',
        'away_team_id',
        'home_team_display_name',
        'away_team_display_name',
        'home_team_abbreviation',
        'away_team_abbreviation',
        'season',
        'week',
        'season_type',
        'game_date',
        'game_time',
        'name',
        'short_name',
        'is_ncaa_tournament',
        'tournament_id',
        'tournament_note',
        'tournament_round',
        'tournament_region',
        'home_seed',
        'away_seed',
        'play_in_target_seed',
        'venue_name',
        'venue_city',
        'venue_state',
        'status',
        'period',
        'game_clock',
        'home_score',
        'away_score',
        'home_linescores',
        'away_linescores',
        'broadcast_networks',
        'odds_api_event_id',
        'odds_data',
        'odds_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'game_date' => 'date',
            'completed_at' => 'datetime',
            'is_ncaa_tournament' => 'boolean',
            'tournament_id' => 'integer',
            'home_seed' => 'integer',
            'away_seed' => 'integer',
            'play_in_target_seed' => 'integer',
            'home_team_id' => 'integer',
            'away_team_id' => 'integer',
            'home_linescores' => 'array',
            'away_linescores' => 'array',
            'broadcast_networks' => 'array',
            'odds_data' => 'array',
            'odds_updated_at' => 'datetime',
        ];
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function plays(): HasMany
    {
        return $this->hasMany(Play::class, 'game_id');
    }

    public function playerStats(): HasMany
    {
        return $this->hasMany(PlayerStat::class, 'game_id');
    }

    public function teamStats(): HasMany
    {
        return $this->hasMany(TeamStat::class, 'game_id');
    }

    public function prediction(): HasOne
    {
        return $this->hasOne(Prediction::class, 'game_id');
    }

    public function playerProps(): HasMany
    {
        return $this->hasMany(\App\Models\CBB\PlayerProp::class, 'game_id');
    }
}
