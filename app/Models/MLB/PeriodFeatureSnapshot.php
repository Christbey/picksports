<?php

namespace App\Models\MLB;

use App\Models\CanonicalPrediction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodFeatureSnapshot extends Model
{
    protected $table = 'mlb_period_feature_snapshots';

    protected $fillable = [
        'game_id',
        'forecast_source',
        'canonical_prediction_id',
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
            'canonical_prediction_id' => 'integer',
            'game_start_at' => 'datetime',
            'features_available_at' => 'datetime',
            'pregame_safe' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        $immutable = function (self $snapshot): void {
            if ($snapshot->getOriginal('forecast_source') === 'canonical') {
                throw new \LogicException('Native MLB period features are immutable.');
            }
        };
        static::updating($immutable);
        static::deleting($immutable);
    }

    public function canonicalPrediction(): BelongsTo
    {
        return $this->belongsTo(CanonicalPrediction::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
