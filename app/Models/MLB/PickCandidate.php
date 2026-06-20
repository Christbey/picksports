<?php

namespace App\Models\MLB;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PickCandidate extends Model
{
    protected $table = 'mlb_pick_candidates';

    protected $fillable = [
        'season',
        'game_id',
        'prediction_id',
        'team_id',
        'player_id',
        'market_type',
        'market_key',
        'side',
        'line',
        'price',
        'book',
        'market_probability',
        'no_vig_probability',
        'model_probability',
        'blend_probability',
        'edge_raw',
        'edge_no_vig',
        'projected_value',
        'score',
        'confidence',
        'status',
        'recommendation_label',
        'is_public',
        'is_tracking_only',
        'is_bet',
        'risk_flags',
        'reason_codes',
        'feature_snapshot',
        'market_snapshot',
        'generated_at',
        'locked_at',
        'game_start_at',
        'result_status',
        'result_value',
        'result_profit_units',
        'closing_price',
        'closing_line',
        'clv',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'season' => 'integer',
            'line' => 'decimal:2',
            'market_probability' => 'decimal:4',
            'no_vig_probability' => 'decimal:4',
            'model_probability' => 'decimal:4',
            'blend_probability' => 'decimal:4',
            'edge_raw' => 'decimal:4',
            'edge_no_vig' => 'decimal:4',
            'projected_value' => 'decimal:3',
            'score' => 'integer',
            'confidence' => 'decimal:4',
            'is_public' => 'boolean',
            'is_tracking_only' => 'boolean',
            'is_bet' => 'boolean',
            'risk_flags' => 'array',
            'reason_codes' => 'array',
            'feature_snapshot' => 'array',
            'market_snapshot' => 'array',
            'generated_at' => 'datetime',
            'locked_at' => 'datetime',
            'game_start_at' => 'datetime',
            'result_value' => 'decimal:3',
            'result_profit_units' => 'decimal:3',
            'closing_line' => 'decimal:2',
            'clv' => 'decimal:4',
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

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
