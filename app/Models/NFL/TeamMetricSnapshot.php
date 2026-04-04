<?php

namespace App\Models\NFL;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMetricSnapshot extends Model
{
    protected $table = 'nfl_team_metric_snapshots';

    protected $fillable = [
        'snapshot_key',
        'team_id',
        'season',
        'wins',
        'losses',
        'predictive_rating',
        'future_strength_of_schedule',
        'recent_form_rating',
        'injury_total_adjustment',
        'calculation_date',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'season' => 'integer',
            'wins' => 'integer',
            'losses' => 'integer',
            'predictive_rating' => 'decimal:3',
            'future_strength_of_schedule' => 'decimal:3',
            'recent_form_rating' => 'decimal:3',
            'injury_total_adjustment' => 'decimal:3',
            'calculation_date' => 'date',
            'captured_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
