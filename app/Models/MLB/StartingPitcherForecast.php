<?php

namespace App\Models\MLB;

use App\Services\Predictions\CanonicalPayloadHasher;
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

    public function save(array $options = []): bool
    {
        if (! $this->exists) {
            return parent::save($options);
        }

        return $this->withCurrentForecast(fn (): bool => parent::save($options));
    }

    public function delete(): ?bool
    {
        throw new LogicException('Starting pitcher forecast history cannot be deleted.');
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        if (! $this->exists || ! is_string($column)) {
            throw new LogicException('Numeric starter forecast updates require a persisted identity.');
        }
        foreach ([$column, ...array_keys($extra)] as $field) {
            if ($field === $this->getKeyName() || in_array($field, self::IMMUTABLE_FORECAST_FIELDS, true)) {
                throw new LogicException("Starting pitcher forecast field [{$field}] is immutable.");
            }
        }

        return $this->withCurrentForecast(fn () => parent::incrementOrDecrement($column, $amount, $extra, $method));
    }

    /** Existing producers lock Game first; this guard never requests a parent lock afterward. */
    private function withCurrentForecast(callable $write): mixed
    {
        if ($this->isDirty($this->getKeyName())) {
            throw new LogicException('Starting pitcher forecast identity is immutable.');
        }

        return $this->getConnection()->transaction(function () use ($write): mixed {
            $persisted = $this->newQuery()->whereKey($this->getRawOriginal($this->getKeyName()))->lockForUpdate()->firstOrFail();
            $comparison = clone $persisted;
            $comparison->setRawAttributes($this->getAttributes());
            foreach (self::IMMUTABLE_FORECAST_FIELDS as $field) {
                // SQL JSON stores object members in its own order; values and list order remain immutable.
                $equivalent = $field === 'evidence'
                    ? hash_equals(app(CanonicalPayloadHasher::class)->hash($persisted->evidence), app(CanonicalPayloadHasher::class)->hash($this->evidence))
                    : $comparison->originalIsEquivalent($field);
                if (! $equivalent) {
                    throw new LogicException("Starting pitcher forecast field [{$field}] is immutable.");
                }
            }

            return $write();
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
