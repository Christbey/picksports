<?php

namespace App\Models\WNBA;

use App\Models\Concerns\ResolvesTeamLogoUrls;
use Database\Factories\WnbaTeamFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    /** @use HasFactory<WnbaTeamFactory> */
    use HasFactory, ResolvesTeamLogoUrls;

    protected $table = 'wnba_teams';

    protected static function newFactory(): WnbaTeamFactory
    {
        return WnbaTeamFactory::new();
    }

    protected $fillable = [
        'espn_id',
        'abbreviation',
        'location',
        'name',
        'school',
        'mascot',
        'display_name',
        'short_display_name',
        'conference',
        'division',
        'color',
        'logo',
        'logo_url',
        'alternate_color',
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

    public function teamMetrics(): HasMany
    {
        return $this->hasMany(TeamMetric::class, 'team_id');
    }

    public function predictions(): Builder
    {
        return Prediction::query()->whereHas('game', function (Builder $query): void {
            $query->where('home_team_id', $this->id)
                ->orWhere('away_team_id', $this->id);
        });
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
}
