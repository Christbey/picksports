<?php

namespace App\Models\CFB;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    /** @use HasFactory<\Database\Factories\CfbTeamFactory> */
    use HasFactory;

    protected $table = 'cfb_teams';

    protected $fillable = [
        'espn_id',
        'name',
        'abbreviation',
        'display_name',
        'short_display_name',
        'logo',
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

    public function fpiRatings(): HasMany
    {
        return $this->hasMany(FpiRating::class, 'team_id');
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class, 'team_id');
    }
}
