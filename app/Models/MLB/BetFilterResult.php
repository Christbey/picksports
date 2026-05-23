<?php

namespace App\Models\MLB;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BetFilterResult extends Model
{
    protected $table = 'mlb_bet_filter_results';

    protected $fillable = [
        'game_id',
        'prediction_id',
        'season',
        'season_type',
        'game_date',
        'as_of_date',
        'filter_version',
        'market',
        'pick_side',
        'team_id',
        'team_name',
        'score',
        'classification',
        'model_probability',
        'market_price',
        'market_implied_probability',
        'probability_edge',
        'edge_runs',
        'model_line',
        'market_line',
        'closing_line',
        'closing_price',
        'clv',
        'reason_codes',
        'risk_flags',
        'metadata',
        'result_hit',
        'actual_margin',
        'actual_total',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'season' => 'integer',
            'game_date' => 'date',
            'as_of_date' => 'date',
            'score' => 'integer',
            'model_probability' => 'decimal:4',
            'market_price' => 'integer',
            'market_implied_probability' => 'decimal:4',
            'probability_edge' => 'decimal:4',
            'edge_runs' => 'decimal:2',
            'model_line' => 'decimal:2',
            'market_line' => 'decimal:2',
            'closing_line' => 'decimal:2',
            'closing_price' => 'integer',
            'clv' => 'decimal:3',
            'reason_codes' => 'array',
            'risk_flags' => 'array',
            'metadata' => 'array',
            'result_hit' => 'boolean',
            'actual_margin' => 'decimal:1',
            'actual_total' => 'decimal:1',
            'graded_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
