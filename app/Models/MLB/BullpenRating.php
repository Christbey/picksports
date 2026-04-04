<?php

namespace App\Models\MLB;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BullpenRating extends Model
{
    protected $table = 'mlb_bullpen_ratings';

    protected $fillable = [
        'team_id',
        'season',
        'season_type',
        'as_of_date',
        'games_sampled',
        'weighted_usage',
        'weighted_era',
        'weighted_whip',
        'strikeouts_per_nine',
        'walks_per_nine',
        'home_runs_per_nine',
        'recent_form_score',
        'workload_penalty',
        'rating_score',
        'rating_rank',
        'calculation_date',
    ];

    protected function casts(): array
    {
        return [
            'season' => 'integer',
            'season_type' => 'string',
            'as_of_date' => 'date',
            'games_sampled' => 'integer',
            'weighted_usage' => 'decimal:3',
            'weighted_era' => 'decimal:3',
            'weighted_whip' => 'decimal:3',
            'strikeouts_per_nine' => 'decimal:3',
            'walks_per_nine' => 'decimal:3',
            'home_runs_per_nine' => 'decimal:3',
            'recent_form_score' => 'decimal:3',
            'workload_penalty' => 'decimal:3',
            'rating_score' => 'decimal:3',
            'rating_rank' => 'integer',
            'calculation_date' => 'date',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
