<?php

namespace App\Models\NFL;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coach extends Model
{
    protected $table = 'nfl_coaches';

    protected $fillable = [
        'espn_id',
        'uid',
        'first_name',
        'last_name',
        'display_name',
        'short_name',
        'experience',
        'career_records',
        'raw_payload',
    ];

    protected $casts = [
        'experience' => 'integer',
        'career_records' => 'array',
        'raw_payload' => 'array',
    ];

    public function teamSeasons(): HasMany
    {
        return $this->hasMany(TeamCoachSeason::class, 'coach_id');
    }
}
