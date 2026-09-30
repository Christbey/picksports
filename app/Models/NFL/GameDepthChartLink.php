<?php

namespace App\Models\NFL;

use App\Models\PredictionFeatureSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class GameDepthChartLink extends Model
{
    public $timestamps = false;

    protected $table = 'nfl_game_depth_chart_links';

    protected $fillable = [
        'game_id', 'team_id', 'side', 'snapshot_id', 'prediction_feature_snapshot_id',
        'observed_at', 'as_of', 'selected_at', 'source', 'identity_status', 'selection_mode', 'evidence',
    ];

    protected function casts(): array
    {
        return ['observed_at' => 'datetime', 'as_of' => 'datetime', 'selected_at' => 'datetime', 'evidence' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Game depth chart links are append-only.'));
        static::deleting(fn () => throw new LogicException('Game depth chart links are append-only.'));
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(DepthChartSnapshot::class, 'snapshot_id');
    }

    public function predictionFeatureSnapshot(): BelongsTo
    {
        return $this->belongsTo(PredictionFeatureSnapshot::class);
    }
}
