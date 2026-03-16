<?php

namespace App\Models\CFB;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamSeasonAffiliation extends Model
{
    protected $table = 'cfb_team_season_affiliations';

    protected $fillable = [
        'team_id',
        'season',
        'subdivision',
        'conference',
        'division',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'season' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function isFbs(): bool
    {
        return (string) $this->subdivision === (string) config('cfb.teams.divisions.fbs', 'FBS');
    }
}
