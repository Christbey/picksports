<?php

namespace App\Models\MLB;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodFeatureSnapshot extends Model
{
    protected $table = 'mlb_period_feature_snapshots';

    protected $fillable = [
        'game_id',
        'feature_version',
        'feature_hash',
        'features',
        'game_start_at',
        'features_available_at',
        'pregame_safe',
        'availability_status',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'game_start_at' => 'datetime',
            'features_available_at' => 'datetime',
            'pregame_safe' => 'boolean',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
