<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BetDecision extends Model
{
    protected $fillable = [
        'decision_run_id',
        'model_run_id',
        'model_artifact_id',
        'shadow_model_output_id',
        'prediction_feature_snapshot_id',
        'game_odds_snapshot_id',
        'source_table',
        'source_id',
        'sport',
        'game_table',
        'game_id',
        'prediction_table',
        'prediction_id',
        'market_type',
        'market_key',
        'side',
        'line',
        'price',
        'bookmaker',
        'market_probability',
        'no_vig_probability',
        'model_probability',
        'blend_probability',
        'edge',
        'projected_value',
        'score',
        'confidence',
        'status',
        'recommendation_label',
        'is_public',
        'is_tracking_only',
        'is_bet',
        'pregame_safe',
        'eligibility_reasons',
        'risk_flags',
        'reason_codes',
        'explanation',
        'feature_snapshot',
        'market_snapshot',
        'decided_at',
        'locked_at',
        'game_start_at',
        'decision_hash',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'is_tracking_only' => 'boolean',
            'is_bet' => 'boolean',
            'pregame_safe' => 'boolean',
            'eligibility_reasons' => 'array',
            'risk_flags' => 'array',
            'reason_codes' => 'array',
            'explanation' => 'array',
            'feature_snapshot' => 'array',
            'market_snapshot' => 'array',
            'decided_at' => 'datetime',
            'locked_at' => 'datetime',
            'game_start_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (BetDecision $decision): void {
            if (! $decision->shadow_model_output_id) {
                return;
            }

            $shadow = $decision->shadowOutput()
                ->with('featureSnapshot')
                ->first();
            $maximumUncertainty = $decision->sport === 'nfl'
                ? config('nfl_ml.shadow.max_uncertainty')
                : null;
            $uncertainty = data_get($shadow?->explanation, 'challenger_outputs.uncertainty')
                ?? data_get($shadow?->featureSnapshot?->outputs, 'challenger_uncertainty')
                ?? data_get($shadow?->featureSnapshot?->model_metadata, 'shadow_inference.challenger_outputs.uncertainty');

            if ($decision->sport === 'nfl') {
                $decision->forceFill([
                    'explanation' => [
                        ...(array) $decision->explanation,
                        'model_uncertainty' => is_numeric($uncertainty) ? (float) $uncertainty : null,
                        'maximum_model_uncertainty' => is_numeric($maximumUncertainty)
                            ? (float) $maximumUncertainty
                            : null,
                        'uncertainty_gate_enabled' => is_numeric($maximumUncertainty),
                    ],
                ]);
            }

            if (! $decision->is_bet) {
                return;
            }

            $reasons = array_values((array) $decision->eligibility_reasons);
            $artifact = $decision->modelArtifact()->first();
            if (! $artifact?->isPromotedForMarket($decision->market_type)) {
                $reasons[] = 'market_model_not_promoted';
            }
            if (is_numeric($maximumUncertainty)) {
                if (! is_numeric($uncertainty)) {
                    $reasons[] = 'model_uncertainty_missing';
                } elseif ((float) $uncertainty > (float) $maximumUncertainty) {
                    $reasons[] = 'model_uncertainty_above_threshold';
                }
            }

            $reasons = array_values(array_unique($reasons));
            if ($reasons === []) {
                return;
            }

            $decision->forceFill([
                'status' => 'shadow_no_bet',
                'recommendation_label' => 'no_bet',
                'is_public' => false,
                'is_tracking_only' => true,
                'is_bet' => false,
                'eligibility_reasons' => $reasons,
                'reason_codes' => array_values(array_unique([
                    ...(array) $decision->reason_codes,
                    'shadow_model_observation',
                ])),
                'explanation' => [
                    ...(array) $decision->explanation,
                    'decision' => 'no_bet',
                    'model_uncertainty' => is_numeric($uncertainty) ? (float) $uncertainty : null,
                    'maximum_model_uncertainty' => is_numeric($maximumUncertainty)
                        ? (float) $maximumUncertainty
                        : null,
                    'uncertainty_gate_enabled' => is_numeric($maximumUncertainty),
                    'why_not_bet' => $reasons,
                ],
            ]);

        });
    }

    public function modelRun(): BelongsTo
    {
        return $this->belongsTo(ModelRun::class);
    }

    public function featureSnapshot(): BelongsTo
    {
        return $this->belongsTo(PredictionFeatureSnapshot::class, 'prediction_feature_snapshot_id');
    }

    public function modelArtifact(): BelongsTo
    {
        return $this->belongsTo(ModelArtifact::class);
    }

    public function shadowOutput(): BelongsTo
    {
        return $this->belongsTo(ShadowModelOutput::class, 'shadow_model_output_id');
    }

    public function oddsSnapshot(): BelongsTo
    {
        return $this->belongsTo(GameOddsSnapshot::class, 'game_odds_snapshot_id');
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(BetSettlement::class);
    }
}
