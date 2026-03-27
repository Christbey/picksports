<?php

namespace App\Models\NFL;

use App\Models\Concerns\ResolvesTeamLogoUrls;
use Database\Factories\NflTeamFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    /** @use HasFactory<NflTeamFactory> */
    use HasFactory, ResolvesTeamLogoUrls;

    protected $table = 'nfl_teams';

    protected $fillable = [
        'espn_id',
        'name',
        'abbreviation',
        'display_name',
        'short_display_name',
        'logo_url',
        'color',
        'alternate_color',
        'location',
        'conference',
        'division',
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

    public function plays(): HasMany
    {
        return $this->hasMany(Play::class, 'possession_team_id');
    }

    public function playerStats(): HasMany
    {
        return $this->hasMany(PlayerStat::class, 'team_id');
    }

    public function teamStats(): HasMany
    {
        return $this->hasMany(TeamStat::class, 'team_id');
    }

    public function eloRatings(): HasMany
    {
        return $this->hasMany(EloRating::class, 'team_id');
    }

    public function predictions(): Builder
    {
        return Prediction::query()->whereHas('game', function (Builder $query): void {
            $query->where('home_team_id', $this->id)
                ->orWhere('away_team_id', $this->id);
        });
    }

    public function teamMetrics(): HasMany
    {
        return $this->hasMany(TeamMetric::class, 'team_id');
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

    protected static function newFactory(): NflTeamFactory
    {
        return NflTeamFactory::new();
    }
}
