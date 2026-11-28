<?php

namespace App\Models\Sports;

use App\Models\PlayerPropGradeRevision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

abstract class AbstractPlayerProp extends Model
{
    public const FROZEN_ENTRY_FIELDS = ['game_id', 'player_id', 'player_name', 'market', 'bookmaker', 'line',
        'over_price', 'under_price', 'recommended_side', 'predicted_over_probability', 'market_over_probability',
        'confidence_score', 'edge_probability', 'data_quality_score', 'match_quality_score', 'context_adjustment_factor',
        'confidence_decomposition'];

    public function gradeRevisions(): HasMany
    {
        return $this->hasMany(PlayerPropGradeRevision::class, 'prop_id')->where('prop_table', $this->getTable());
    }

    public function save(array $options = [])
    {
        if (! $this->exists || ! $this->isDirty(self::FROZEN_ENTRY_FIELDS)) {
            return parent::save($options);
        }

        return $this->withMutableEntry(fn () => parent::save($options));
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        if (! in_array($column, self::FROZEN_ENTRY_FIELDS, true) && array_intersect(array_keys($extra), self::FROZEN_ENTRY_FIELDS) === []) {
            return parent::incrementOrDecrement($column, $amount, $extra, $method);
        }

        return $this->withMutableEntry(fn () => parent::incrementOrDecrement($column, $amount, $extra, $method));
    }

    private function withMutableEntry(\Closure $write)
    {
        return $this->getConnection()->transaction(function () use ($write) {
            $this->game()->getRelated()->newQuery()->whereKey($this->getOriginal('game_id'))->lockForUpdate()->first();
            $current = $this->newQuery()->whereKey($this->id)->lockForUpdate()->firstOrFail();
            if ($current->graded_at !== null || $current->gradeRevisions()->lockForUpdate()->first() !== null) {
                throw new LogicException('Graded or receipted prop entry evidence is frozen; create a new quote instead.');
            }

            return $write();
        });
    }

    protected $fillable = [
        'game_id',
        'player_id',
        'odds_api_event_id',
        'player_name',
        'market',
        'bookmaker',
        'line',
        'over_price',
        'under_price',
        'raw_data',
        'fetched_at',
        'actual_value',
        'hit_over',
        'error',
        'recommended_side',
        'confidence_score',
        'predicted_over_probability',
        'market_over_probability',
        'edge_probability',
        'data_quality_score',
        'match_quality_score',
        'context_adjustment_factor',
        'confidence_decomposition',
        'narrative_json',
        'narrative_provider',
        'narrative_model',
        'narrative_input_hash',
        'narrative_latency_ms',
        'narrative_generated_at',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'line' => 'decimal:2',
            'over_price' => 'integer',
            'under_price' => 'integer',
            'raw_data' => 'array',
            'fetched_at' => 'datetime',
            'actual_value' => 'decimal:2',
            'hit_over' => 'boolean',
            'error' => 'decimal:2',
            'confidence_score' => 'integer',
            'predicted_over_probability' => 'decimal:2',
            'market_over_probability' => 'decimal:2',
            'edge_probability' => 'decimal:2',
            'data_quality_score' => 'integer',
            'match_quality_score' => 'integer',
            'context_adjustment_factor' => 'decimal:3',
            'confidence_decomposition' => 'array',
            'narrative_json' => 'array',
            'narrative_latency_ms' => 'integer',
            'narrative_generated_at' => 'datetime',
            'graded_at' => 'datetime',
        ];
    }
}
