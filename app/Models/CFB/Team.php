<?php

namespace App\Models\CFB;

use App\Models\Concerns\ResolvesTeamLogoUrls;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    /** @use HasFactory<\Database\Factories\CfbTeamFactory> */
    use HasFactory, ResolvesTeamLogoUrls;

    protected $table = 'cfb_teams';

    protected static function newFactory(): \Database\Factories\CfbTeamFactory
    {
        return \Database\Factories\CfbTeamFactory::new();
    }

    protected $fillable = [
        'espn_id',
        'cfbd_team_id',
        'name',
        'abbreviation',
        'display_name',
        'short_display_name',
        'logo',
        'logo_url',
        'color',
        'alternate_color',
        'location',
        'school',
        'mascot',
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

    public function seasonAffiliations(): HasMany
    {
        return $this->hasMany(TeamSeasonAffiliation::class, 'team_id');
    }

    public function fpiRatings(): HasMany
    {
        return $this->hasMany(FpiRating::class, 'team_id');
    }

    public function predictions(): Builder
    {
        return Prediction::query()->whereHas('game', function (Builder $query): void {
            $query->where('home_team_id', $this->id)
                ->orWhere('away_team_id', $this->id);
        });
    }

    public function scopeFbs(Builder $query): Builder
    {
        return $query->where('division', config('cfb.teams.divisions.fbs', 'FBS'));
    }

    public function seasonAffiliation(int $season): ?TeamSeasonAffiliation
    {
        if ($this->relationLoaded('seasonAffiliations')) {
            return $this->seasonAffiliations->firstWhere('season', $season);
        }

        return $this->seasonAffiliations()->where('season', $season)->first();
    }

    public function isFbs(): bool
    {
        return (string) $this->division === (string) config('cfb.teams.divisions.fbs', 'FBS');
    }

    public function isFbsForSeason(int $season): bool
    {
        $affiliation = $this->seasonAffiliation($season);

        if ($affiliation) {
            return $affiliation->isFbs();
        }

        return app(\App\Support\CfbSeasonAffiliationResolver::class)->isFbs($this, $season);
    }
}
