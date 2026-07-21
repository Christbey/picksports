<?php

namespace App\Models\NFL;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamCoachSeason extends Model
{
    protected $table = 'nfl_team_coach_seasons';

    protected $fillable = [
        'coach_id',
        'team_id',
        'season',
        'role',
        'experience',
        'regular_season_record',
        'source_ref',
        'raw_payload',
    ];

    protected $casts = [
        'season' => 'integer',
        'experience' => 'integer',
        'regular_season_record' => 'array',
        'raw_payload' => 'array',
    ];

    public function coach(): BelongsTo
    {
        return $this->belongsTo(Coach::class, 'coach_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
