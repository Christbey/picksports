<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NflSignalGrade extends Model
{
    protected $fillable = [
        'nfl_signal_observation_id',
        'bet_decision_id',
        'bet_settlement_id',
        'evaluation_key',
        'evaluation_source',
        'market_type',
        'direction',
        'result_status',
        'hit',
        'model_probability',
        'baseline_probability',
        'actual_probability',
        'line',
        'model_value',
        'actual_value',
        'absolute_error',
        'baseline_error',
        'error_lift',
        'brier_score',
        'baseline_brier_score',
        'calibration_lift',
        'price',
        'profit_units',
        'shadow_profit_units',
        'clv',
        'is_actual_bet',
        'graded_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'hit' => 'boolean',
            'is_actual_bet' => 'boolean',
            'graded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(NflSignalObservation::class, 'nfl_signal_observation_id');
    }

    public function betDecision(): BelongsTo
    {
        return $this->belongsTo(BetDecision::class);
    }

    public function betSettlement(): BelongsTo
    {
        return $this->belongsTo(BetSettlement::class);
    }
}
