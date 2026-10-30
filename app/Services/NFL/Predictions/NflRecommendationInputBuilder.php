<?php

namespace App\Services\NFL\Predictions;

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\NFL\Game;
use App\Services\NFL\NflEvidenceForecastSource;
use App\Services\NFL\NflTrueEpaReadinessService;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Services\Predictions\ModelRunRecorder;

class NflRecommendationInputBuilder
{
    public const VERSION = 'nfl-recommendation-context-v1';

    public function __construct(
        private readonly GeneratePredictionFromHistoricalElo $calculator,
        private readonly NflTrueEpaReadinessService $epa,
        private readonly CanonicalPayloadHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function capture(Game $game): array
    {
        $this->epa->prepare(collect([$game]));
        $preview = $this->calculator->previewPregame($game);
        if ($preview === null) {
            throw new PredictionLifecycleException('NFL recommendation evidence is unavailable.');
        }
        $configuration = [
            'minimum_spread_edge' => (float) config('nfl.predictions.analysis_layer.min_spread_edge', 2.0),
            'moneyline_play_enabled' => (bool) config('nfl.betting.moneyline.play_enabled', false),
        ];

        return [
            'version' => self::VERSION,
            'evidence_source_version' => NflEvidenceForecastSource::VERSION,
            'runtime_configuration_hash' => app(ModelRunRecorder::class)->configHash('nfl'),
            'captured_at' => now()->toIso8601String(),
            'authority' => 'nfl_deterministic_analysis_and_pro_signal_consensus',
            'forecast_role' => 'recommendation_evidence_only',
            'model_version' => $preview['model_version'],
            'outputs' => [
                ...$preview['outputs'],
                'calibration_inputs' => array_intersect_key(
                    (array) data_get($preview, 'model_metadata.calibration_inputs', []),
                    array_flip(['point', 'win_probability']),
                ),
            ],
            'model_metadata' => [
                ...array_intersect_key((array) $preview['model_metadata'], array_flip(['true_epa', 'analysis_layer'])),
                'qb_form' => collect(['home', 'away'])->mapWithKeys(fn (string $side): array => [
                    $side => array_intersect_key((array) data_get($preview['model_metadata'], 'qb_form.'.$side, []),
                        array_flip(['qb_id', 'identity_status'])),
                ])->all(),
            ],
            'configuration' => $configuration,
            'configuration_hash' => $this->hasher->hash($configuration),
        ];
    }
}
