<?php

namespace App\Models\MLB;

use App\Models\Concerns\ResolvesTeamLogoUrls;
use Database\Factories\MlbTeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    /** @use HasFactory<MlbTeamFactory> */
    use HasFactory, ResolvesTeamLogoUrls;

    protected $table = 'mlb_teams';

    protected static function newFactory(): MlbTeamFactory
    {
        return MlbTeamFactory::new();
    }

    protected $fillable = [
        'espn_id',
        'abbreviation',
        'location',
        'name',
        'nickname',
        'league',
        'division',
        'color',
        'logo_url',
        'elo_rating',
    ];

    public function players(): HasMany
    {
        return $this->hasMany(Player::class, 'team_id');
    }

    public function homeGames(): HasMany
    {
        return $this->hasMany(Game::class, 'home_team_id');
    }

    public function awayGames(): HasMany
    {
        return $this->hasMany(Game::class, 'away_team_id');
    }

    public function battingPlays(): HasMany
    {
        return $this->hasMany(Play::class, 'batting_team_id');
    }

    public function pitchingPlays(): HasMany
    {
        return $this->hasMany(Play::class, 'pitching_team_id');
    }

    public function playerStats(): HasMany
    {
        return $this->hasMany(PlayerStat::class, 'team_id');
    }

    public function teamStats(): HasMany
    {
        return $this->hasMany(TeamStat::class, 'team_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(TeamMetric::class, 'team_id');
    }

    public function eloRatings(): HasMany
    {
        return $this->hasMany(EloRating::class, 'team_id');
    }

    public function playoffForecasts(): HasMany
    {
        return $this->hasMany(PlayoffForecast::class, 'team_id');
    }

    public function playerInjuries(): HasMany
    {
        return $this->hasMany(PlayerInjury::class, 'team_id');
    }

    public function activePlayerInjuries(): HasMany
    {
        return $this->playerInjuries()
            ->where('is_active', true)
            ->orderByDesc('updated_at');
    }

    public function depthChartEntries(): HasMany
    {
        return $this->hasMany(DepthChartEntry::class, 'team_id');
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->location.' '.$this->name;
    }
}
