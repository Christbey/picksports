<?php

namespace App\Models\MLB;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StartingPitcherForecast extends Model
{
    private const IMMUTABLE_FORECAST_FIELDS = [
        'game_id',
        'team_id',
        'season',
        'side',
        'forecast_hash',
        'model_version',
        'prediction_source',
        'predicted_pitcher_espn_id',
        'confidence',
        'predicted_pitcher_rating',
        'predicted_rating_source',
        'evidence',
        'forecasted_at',
        'game_start_at',
        'known_before_game_start',
    ];

    protected $table = 'mlb_starting_pitcher_forecasts';

    protected $fillable = [
        'game_id',
        'team_id',
        'season',
        'side',
        'forecast_hash',
        'model_version',
        'prediction_source',
        'predicted_pitcher_espn_id',
        'confidence',
        'predicted_pitcher_rating',
        'predicted_rating_source',
        'evidence',
        'forecasted_at',
        'game_start_at',
        'known_before_game_start',
        'actual_pitcher_espn_id',
        'actual_pitcher_rating',
        'actual_rating_source',
        'confirmation_source',
        'confirmed_at',
        'is_correct',
        'starter_changed',
        'confidence_error',
        'brier_score',
        'log_loss',
        'rating_difference',
        'grade',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'predicted_pitcher_rating' => 'float',
            'actual_pitcher_rating' => 'float',
            'evidence' => 'array',
            'forecasted_at' => 'datetime',
            'game_start_at' => 'datetime',
            'known_before_game_start' => 'boolean',
            'confirmed_at' => 'datetime',
            'is_correct' => 'boolean',
            'starter_changed' => 'boolean',
            'confidence_error' => 'float',
            'brier_score' => 'float',
            'log_loss' => 'float',
            'rating_difference' => 'float',
            'graded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $forecast): void {
            foreach (self::IMMUTABLE_FORECAST_FIELDS as $field) {
                if ($forecast->isDirty($field)) {
                    throw new LogicException("Starting pitcher forecast field [{$field}] is immutable.");
                }
            }
        });
    }

    public function scopePregameSafe(Builder $query): Builder
    {
        return $query->where('known_before_game_start', true);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function predictedPitcher(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'predicted_pitcher_espn_id', 'espn_id');
    }

    public function actualPitcher(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'actual_pitcher_espn_id', 'espn_id');
    }
}
