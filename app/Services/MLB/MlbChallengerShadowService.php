<?php

namespace App\Services\MLB;

use App\Models\MLB\Game;
use App\Models\ModelArtifact;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Services\ML\ShadowArtifactSelector;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class MlbChallengerShadowService
{
    public const DISABLED = 'mlb_model_shadow_disabled';

    public const MISSING_ARTIFACT = 'mlb_model_artifact_missing';

    public const NO_SAFE_SNAPSHOT = 'mlb_canonical_pregame_snapshot_missing';

    public const PITCHERS_NOT_READY = 'mlb_probable_pitchers_not_ready';

    public const FEATURES_NOT_READY = 'mlb_model_features_not_ready';

    public const INFERENCE_FAILED = 'mlb_model_inference_failed';

    public function __construct(
        private readonly MlbTabularModelInferenceService $inference,
        private readonly MlbShadowFeatureBuilder $features,
        private readonly ModelRunRecorder $runs,
        private readonly ShadowArtifactSelector $artifacts,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     message: string,
     *     artifact_id: ?string,
     *     artifact_ids: list<string>,
     *     considered: int,
     *     inferred: int,
     *     outputs_created: int,
     *     reasons: array<string, int>
     * }
     */
    public function run(?string $artifactId = null, ?int $gameId = null, int $limit = 0): array
    {
        if (! (bool) config('mlb_ml.shadow.enabled', false)
            || config('mlb_ml.mode', 'shadow') !== 'shadow') {
            return $this->result('disabled', self::DISABLED, null);
        }

        $configuredId = trim((string) config('mlb_ml.shadow.artifact_id', ''));
        $databaseSelectionActive = (bool) config('mlb_ml.shadow.auto_select', true)
            && $this->artifacts->activeChallenger('mlb', 'mlb_tabular_bundle') !== null;
        $pinnedArtifactId = filled($artifactId)
            ? $artifactId
            : ($configuredId !== '' && ! $databaseSelectionActive ? $configuredId : null);
        $artifacts = $this->artifacts->inferenceCohort(
            'mlb',
            'mlb_tabular_bundle',
            $pinnedArtifactId,
        );
        if ($artifacts->isEmpty()) {
            return $this->result('not_ready', self::MISSING_ARTIFACT, null);
        }

        $snapshots = $this->canonicalSnapshots($gameId, $limit);
        if ($snapshots->isEmpty()) {
            return $this->result('not_ready', self::NO_SAFE_SNAPSHOT, $artifacts->first()->id);
        }

        $inferred = 0;
        $outputsCreated = 0;
        $reasons = [];
        foreach ($snapshots as $snapshot) {
            $game = Game::query()->find($snapshot->game_id);
            if (! $game || $game->status !== 'STATUS_SCHEDULED') {
                $this->increment($reasons, self::NO_SAFE_SNAPSHOT);

                continue;
            }
            if ((bool) config('mlb_ml.shadow.require_probable_pitchers', true)
                && ! $this->pitchersReady($snapshot, $game)) {
                $this->increment($reasons, self::PITCHERS_NOT_READY);

                continue;
            }

            $features = $this->features->build($snapshot);
            if (! $this->featuresReady($features)) {
                $this->increment($reasons, self::FEATURES_NOT_READY);

                continue;
            }

            foreach ($artifacts as $artifact) {
                try {
                    $output = $this->inference->predict($artifact, $features);
                } catch (Throwable $exception) {
                    Log::warning('MLB tabular shadow inference failed.', [
                        'artifact_id' => $artifact->id,
                        'game_id' => $game->id,
                        'snapshot_id' => $snapshot->id,
                        'reason' => self::INFERENCE_FAILED,
                        'exception' => $exception->getMessage(),
                    ]);
                    $this->runs->create(
                        sport: 'mlb',
                        runType: 'shadow_inference',
                        modelVersion: $artifact->model_version,
                        featureVersion: $artifact->feature_version,
                        blendVersion: 'shadow-inference-v1',
                        parameters: [
                            'model_artifact_id' => $artifact->id,
                            'artifact_hash' => $artifact->artifact_hash,
                            'game_id' => $game->id,
                            'snapshot_id' => $snapshot->id,
                        ],
                        metadata: [
                            'error' => $exception->getMessage(),
                            'reason' => self::INFERENCE_FAILED,
                        ],
                        status: 'failed',
                        completedAt: now(),
                    );
                    $this->increment($reasons, self::INFERENCE_FAILED);

                    continue;
                }

                $inferred++;
                $outputsCreated += $this->recordOutputs($artifact, $snapshot, $output);
            }
        }

        $status = $inferred > 0
            ? 'completed'
            : (($reasons[self::INFERENCE_FAILED] ?? 0) > 0 ? 'failed' : 'not_ready');

        return [
            'status' => $status,
            'message' => match ($status) {
                'completed' => 'MLB tabular shadow inference completed.',
                'failed' => 'MLB tabular shadow inference failed.',
                default => 'MLB tabular shadow inference found no ready snapshots.',
            },
            'artifact_id' => $artifacts->first()->id,
            'artifact_ids' => $artifacts->pluck('id')->values()->all(),
            'considered' => $snapshots->count(),
            'inferred' => $inferred,
            'outputs_created' => $outputsCreated,
            'reasons' => $reasons,
        ];
    }

    /**
     * @return Collection<int, PredictionFeatureSnapshot>
     */
    private function canonicalSnapshots(?int $gameId, int $limit): Collection
    {
        $query = PredictionFeatureSnapshot::query()
            ->where('sport', 'mlb')
            ->where('prediction_table', 'mlb_predictions')
            ->where('pregame_safe', true)
            ->where('availability_status', 'observed_pregame')
            ->whereNotNull('game_start_at')
            ->whereNotNull('features_available_at')
            ->where('game_start_at', '>', now())
            ->whereColumn('generated_at', '<', 'game_start_at')
            ->whereColumn('features_available_at', '<=', 'game_start_at')
            ->when($gameId, fn (Builder $builder) => $builder->where('game_id', $gameId))
            ->whereHas(
                'modelRun',
                fn (Builder $builder) => $builder->where('sport', 'mlb'),
            )
            ->orderBy('generated_at')
            ->orderBy('id')
            ->get()
            ->groupBy('game_id')
            ->map(
                fn (Collection $group): PredictionFeatureSnapshot => $group
                    ->sortByDesc(fn (PredictionFeatureSnapshot $snapshot): array => [
                        $snapshot->generated_at?->getTimestamp() ?? 0,
                        $snapshot->id,
                    ])
                    ->first(),
            )
            ->sortBy(fn (PredictionFeatureSnapshot $snapshot): array => [
                $snapshot->game_start_at?->getTimestamp() ?? PHP_INT_MAX,
                $snapshot->game_id,
            ])
            ->values();

        return $limit > 0 ? $query->take($limit)->values() : $query;
    }

    /**
     * @param  array<string, int|float|null>  $features
     */
    private function featuresReady(array $features): bool
    {
        foreach ((array) config('mlb_ml.shadow.required_features', []) as $required) {
            if (! is_string($required)
                || ! array_key_exists($required, $features)
                || ! is_numeric($features[$required])) {
                return false;
            }
        }

        return true;
    }

    private function pitchersReady(PredictionFeatureSnapshot $snapshot, Game $game): bool
    {
        $capturedHomeId = $this->capturedPitcherId($snapshot, 'home');
        $capturedAwayId = $this->capturedPitcherId($snapshot, 'away');
        $currentHomeId = trim((string) $game->probable_home_pitcher_espn_id);
        $currentAwayId = trim((string) $game->probable_away_pitcher_espn_id);

        return $capturedHomeId !== ''
            && $capturedAwayId !== ''
            && $currentHomeId !== ''
            && $currentAwayId !== ''
            && hash_equals($capturedHomeId, $currentHomeId)
            && hash_equals($capturedAwayId, $currentAwayId);
    }

    private function capturedPitcherId(PredictionFeatureSnapshot $snapshot, string $side): string
    {
        return trim((string) (
            data_get($snapshot->features, "{$side}_probable_pitcher_espn_id")
            ?? data_get($snapshot->model_metadata, "pitcher_inputs.{$side}_probable_pitcher_espn_id")
            ?? ''
        ));
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function recordOutputs(
        ModelArtifact $artifact,
        PredictionFeatureSnapshot $snapshot,
        array $output,
    ): int {
        $inferenceRun = $this->runs->forPrediction(
            sport: 'mlb',
            modelVersion: $artifact->model_version,
            featureVersion: $artifact->feature_version,
            blendVersion: 'shadow-inference-v1',
            metadata: [
                'run_type' => 'shadow_inference',
                'parameters' => [
                    'model_artifact_id' => $artifact->id,
                    'artifact_hash' => $artifact->artifact_hash,
                ],
            ],
        );
        $baselineOutputs = [
            'win_probability' => $this->number(data_get($snapshot->outputs, 'win_probability')),
            'predicted_spread' => $this->number(data_get($snapshot->outputs, 'predicted_spread')),
            'predicted_total' => $this->number(data_get($snapshot->outputs, 'predicted_total')),
        ];
        $challengerOutputs = [
            'win_probability' => $output['home_win_probability'],
            'predicted_spread' => $output['expected_home_margin'],
            'predicted_total' => $output['expected_total'],
            'home_cover_probability' => $output['home_cover_probability'],
            'over_probability' => $output['over_probability'],
            'uncertainty' => $output['uncertainty'],
        ];
        $created = 0;

        foreach ([
            'win_probability' => 'win_probability',
            'spread' => 'predicted_spread',
            'total' => 'predicted_total',
        ] as $marketType => $field) {
            $baseline = $baselineOutputs[$field];
            $challenger = $challengerOutputs[$field];
            if ($baseline === null) {
                continue;
            }
            $promoted = $artifact->isPromotedForMarket($marketType)
                && $artifact->promoted_at !== null
                && $artifact->promoted_at->lessThanOrEqualTo(now());
            $shadow = ShadowModelOutput::query()->firstOrCreate(
                [
                    'model_artifact_id' => $artifact->id,
                    'prediction_feature_snapshot_id' => $snapshot->id,
                    'market_type' => $marketType,
                ],
                [
                    'inference_run_id' => $inferenceRun->id,
                    'sport' => 'mlb',
                    'game_table' => 'mlb_games',
                    'game_id' => $snapshot->game_id,
                    'prediction_table' => $snapshot->prediction_table,
                    'prediction_id' => $snapshot->prediction_id,
                    'baseline_output' => $baseline,
                    'challenger_output' => $challenger,
                    'output_delta' => $challenger - $baseline,
                    'status' => $promoted ? 'promoted_shadow' : 'shadow',
                    'explanation' => [
                        'artifact_id' => $artifact->id,
                        'artifact_hash' => $artifact->artifact_hash,
                        'artifact_status' => $artifact->status,
                        'training_run_id' => $artifact->training_run_id,
                        'model_run_id' => $output['model_run_id'],
                        'config_hash' => $artifact->trainingRun?->config_hash,
                        'dataset_hash' => $output['dataset_hash'],
                        'feature_hash' => $output['feature_hash'],
                        'market_promoted' => $promoted,
                        'market_promotion' => [
                            'win_probability' => $artifact->isPromotedForMarket('win_probability'),
                            'spread' => $artifact->isPromotedForMarket('spread'),
                            'total' => $artifact->isPromotedForMarket('total'),
                        ],
                        'multi_market_contract' => true,
                        'baseline_outputs' => $baselineOutputs,
                        'challenger_outputs' => $challengerOutputs,
                        'apply_to_live_output' => false,
                        'public_output_changed' => false,
                        'active_source' => 'baseline',
                        'reason' => $promoted
                            ? 'promoted_mlb_model_tracking_shadow'
                            : 'mlb_challenger_tracking_shadow',
                    ],
                    'generated_at' => now(),
                ],
            );
            $created += $shadow->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, int>  $reasons
     */
    private function increment(array &$reasons, string $reason): void
    {
        $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
    }

    /**
     * @return array{
     *     status: string,
     *     message: string,
     *     artifact_id: ?string,
     *     artifact_ids: list<string>,
     *     considered: int,
     *     inferred: int,
     *     outputs_created: int,
     *     reasons: array<string, int>
     * }
     */
    private function result(string $status, string $reason, ?string $artifactId): array
    {
        return [
            'status' => $status,
            'message' => $reason,
            'artifact_id' => $artifactId,
            'artifact_ids' => $artifactId === null ? [] : [$artifactId],
            'considered' => 0,
            'inferred' => 0,
            'outputs_created' => 0,
            'reasons' => [$reason => 1],
        ];
    }
}
