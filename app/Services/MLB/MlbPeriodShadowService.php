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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MlbPeriodShadowService
{
    public const DISABLED = 'mlb_period_model_shadow_disabled';

    public const MISSING_ARTIFACT = 'mlb_period_model_artifact_missing';

    public const NO_SAFE_SNAPSHOT = 'mlb_period_canonical_pregame_snapshot_missing';

    public const INFERENCE_FAILED = 'mlb_period_model_inference_failed';

    public function __construct(
        private readonly MlbPeriodModelInferenceService $inference,
        private readonly MlbPeriodFeatureStore $featureStore,
        private readonly ModelRunRecorder $runs,
        private readonly ShadowArtifactSelector $artifacts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(?string $artifactId = null, ?int $gameId = null, int $limit = 0): array
    {
        if (! (bool) config('mlb_ml.period_models.enabled', false)
            || config('mlb_ml.mode', 'shadow') !== 'shadow') {
            return $this->result('disabled', self::DISABLED);
        }

        $artifacts = $this->artifacts->inferenceCohort(
            'mlb',
            'mlb_period_bundle',
            $artifactId,
        );
        if ($artifacts->isEmpty()) {
            return $this->result('not_ready', self::MISSING_ARTIFACT);
        }

        $snapshots = $this->canonicalSnapshots($gameId, $limit);
        if ($snapshots->isEmpty()) {
            return $this->result('not_ready', self::NO_SAFE_SNAPSHOT, $artifacts);
        }

        $games = Game::query()
            ->whereKey($snapshots->pluck('game_id'))
            ->where('status', config('mlb.statuses.scheduled', 'STATUS_SCHEDULED'))
            ->get()
            ->keyBy('id');
        $this->featureStore->materialize($games->values());
        $featuresByGame = $this->featureStore->forGames($games->values());

        $inferred = 0;
        $created = 0;
        $reasons = [];
        foreach ($snapshots as $snapshot) {
            $game = $games->get((int) $snapshot->game_id);
            if (! $game) {
                $this->increment($reasons, self::NO_SAFE_SNAPSHOT);

                continue;
            }

            $featuresByMarket = $featuresByGame[(int) $game->id] ?? [];
            if ($featuresByMarket === []) {
                $this->increment($reasons, self::NO_SAFE_SNAPSHOT);

                continue;
            }
            foreach ($artifacts as $artifact) {
                try {
                    $outputs = $this->inference->predict($artifact, $featuresByMarket);
                    $inferred++;
                    $created += $this->recordOutputs(
                        $artifact,
                        $snapshot,
                        $featuresByMarket,
                        $outputs,
                    );
                } catch (Throwable $exception) {
                    Log::warning('MLB period shadow inference failed.', [
                        'artifact_id' => $artifact->id,
                        'game_id' => $game->id,
                        'snapshot_id' => $snapshot->id,
                        'exception' => $exception->getMessage(),
                    ]);
                    $this->runs->create(
                        sport: 'mlb',
                        runType: 'shadow_inference',
                        modelVersion: $artifact->model_version,
                        featureVersion: $artifact->feature_version,
                        blendVersion: 'mlb-period-shadow-v1',
                        parameters: [
                            'model_artifact_id' => $artifact->id,
                            'game_id' => $game->id,
                            'snapshot_id' => $snapshot->id,
                        ],
                        metadata: [
                            'reason' => self::INFERENCE_FAILED,
                            'error' => $exception->getMessage(),
                        ],
                        status: 'failed',
                        completedAt: now(),
                    );
                    $this->increment($reasons, self::INFERENCE_FAILED);
                }
            }
        }

        return [
            'status' => $inferred > 0 ? 'completed' : ($reasons === [] ? 'not_ready' : 'failed'),
            'message' => $inferred > 0
                ? 'MLB F3/F5 shadow inference completed.'
                : 'MLB F3/F5 shadow inference produced no outputs.',
            'artifact_id' => $artifacts->first()?->id,
            'artifact_ids' => $artifacts->pluck('id')->values()->all(),
            'considered' => $snapshots->count(),
            'inferred' => $inferred,
            'outputs_created' => $created,
            'reasons' => $reasons,
        ];
    }

    /**
     * @return Collection<int, PredictionFeatureSnapshot>
     */
    private function canonicalSnapshots(?int $gameId, int $limit): Collection
    {
        $rankedSnapshots = PredictionFeatureSnapshot::query()
            ->select([
                'id',
                'game_id',
                'game_start_at',
                'generated_at',
            ])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY game_id ORDER BY generated_at DESC, id DESC) AS snapshot_rank')
            ->where('sport', 'mlb')
            ->where('prediction_table', 'mlb_predictions')
            ->where('pregame_safe', true)
            ->where('availability_status', 'observed_pregame')
            ->whereNotNull('game_start_at')
            ->whereNotNull('features_available_at')
            ->where('game_start_at', '>', now())
            ->whereColumn('generated_at', '<', 'game_start_at')
            ->whereColumn('features_available_at', '<=', 'game_start_at')
            ->when($gameId, fn (Builder $query) => $query->where('game_id', $gameId));

        $snapshotIds = DB::query()
            ->fromSub($rankedSnapshots->toBase(), 'ranked_snapshots')
            ->where('snapshot_rank', 1)
            ->orderBy('game_start_at')
            ->orderBy('game_id')
            ->when($limit > 0, fn ($query) => $query->limit($limit))
            ->pluck('id');

        $snapshotsById = PredictionFeatureSnapshot::query()
            ->whereKey($snapshotIds)
            ->get()
            ->keyBy('id');

        return $snapshotIds
            ->map(fn (int $snapshotId): ?PredictionFeatureSnapshot => $snapshotsById->get($snapshotId))
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, array<string, float|int|null>>  $featuresByMarket
     * @param  list<array<string, mixed>>  $outputs
     */
    private function recordOutputs(
        ModelArtifact $artifact,
        PredictionFeatureSnapshot $snapshot,
        array $featuresByMarket,
        array $outputs,
    ): int {
        $inferenceRun = $this->runs->forPrediction(
            sport: 'mlb',
            modelVersion: $artifact->model_version,
            featureVersion: $artifact->feature_version,
            blendVersion: 'mlb-period-shadow-v1',
            metadata: [
                'run_type' => 'shadow_inference',
                'parameters' => [
                    'model_artifact_id' => $artifact->id,
                    'artifact_hash' => $artifact->artifact_hash,
                ],
            ],
        );
        $created = 0;

        foreach ($outputs as $output) {
            $market = (string) ($output['market_type'] ?? '');
            $features = $featuresByMarket[$market] ?? null;
            if (! is_array($features)) {
                continue;
            }
            $baseline = (float) ($features['feature_elo_home_win_probability'] ?? 0.5);
            $challenger = (float) $output['conditional_home_win_probability'];
            $promoted = $artifact->isPromotedForMarket($market)
                && $artifact->promoted_at?->lessThanOrEqualTo(now());
            $shadow = ShadowModelOutput::query()->firstOrCreate(
                [
                    'model_artifact_id' => $artifact->id,
                    'prediction_feature_snapshot_id' => $snapshot->id,
                    'market_type' => $market,
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
                            'first_3_moneyline' => $artifact->isPromotedForMarket('first_3_moneyline'),
                            'first_5_moneyline' => $artifact->isPromotedForMarket('first_5_moneyline'),
                        ],
                        'multi_market_contract' => true,
                        'period_moneyline_contract' => true,
                        'baseline_outputs' => [
                            'conditional_home_win_probability' => $baseline,
                        ],
                        'challenger_outputs' => [
                            'home_win_probability' => (float) $output['home_win_probability'],
                            'away_win_probability' => (float) $output['away_win_probability'],
                            'tie_probability' => (float) $output['tie_probability'],
                            'conditional_home_win_probability' => $challenger,
                            'conditional_away_win_probability' => (float) $output['conditional_away_win_probability'],
                            'fair_home_price' => (int) $output['fair_home_price'],
                            'fair_away_price' => (int) $output['fair_away_price'],
                            'uncertainty' => (float) $output['uncertainty'],
                            'model_name' => (string) $output['model_name'],
                            'calibration_method' => (string) $output['calibration_method'],
                        ],
                        'period_features' => $features,
                        'apply_to_live_output' => false,
                        'public_output_changed' => false,
                        'active_source' => 'baseline',
                        'reason' => $promoted
                            ? 'promoted_mlb_period_model_tracking_shadow'
                            : 'mlb_period_challenger_tracking_shadow',
                    ],
                    'generated_at' => now(),
                ],
            );
            $created += $shadow->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    /**
     * @param  array<string, int>  $reasons
     */
    private function increment(array &$reasons, string $reason): void
    {
        $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
    }

    /**
     * @param  Collection<int, ModelArtifact>|null  $artifacts
     * @return array<string, mixed>
     */
    private function result(string $status, string $reason, ?Collection $artifacts = null): array
    {
        return [
            'status' => $status,
            'message' => $reason,
            'artifact_id' => $artifacts?->first()?->id,
            'artifact_ids' => $artifacts?->pluck('id')->values()->all() ?? [],
            'considered' => 0,
            'inferred' => 0,
            'outputs_created' => 0,
            'reasons' => [$reason => 1],
        ];
    }
}
