<?php

namespace App\Services\MLB;

use App\Application\Sports\ReadModels\CanonicalShadowSnapshot;
use App\Models\MLB\Game;
use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Services\ML\ShadowArtifactSelector;
use App\Services\Predictions\CanonicalShadowInputs;
use App\Services\Predictions\ModelRunRecorder;
use App\Services\Predictions\PredictionDeliverySource;
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
                    $commits = app(MlbInferenceOutputCommit::class);
                    $prepared = $commits->prepare($artifact, $snapshot, $features);
                    $output = $this->inference->predict($artifact, $features);
                    $created = $commits->commit($prepared, $output,
                        fn (ModelArtifact $current, PredictionFeatureSnapshot|CanonicalShadowSnapshot $target, array $values, array $receipt, callable $assertOwned): int => $this->recordOutputs($current, $target, $values, $receipt, $assertOwned));
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
                $outputsCreated += $created;
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
        if (app(PredictionDeliverySource::class)->usesCanonical('mlb')) {
            return app(CanonicalShadowInputs::class)->forGames('mlb', $gameId, $limit);
        }
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
                    ->sortByDesc(fn (PredictionFeatureSnapshot|CanonicalShadowSnapshot $snapshot): array => [
                        $snapshot->generated_at?->getTimestamp() ?? 0,
                        $snapshot->id,
                    ])
                    ->first(),
            )
            ->sortBy(fn (PredictionFeatureSnapshot|CanonicalShadowSnapshot $snapshot): array => [
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

    private function pitchersReady(PredictionFeatureSnapshot|CanonicalShadowSnapshot $snapshot, Game $game): bool
    {
        $capturedHomeId = $this->capturedPitcherId($snapshot, 'home');
        $capturedAwayId = $this->capturedPitcherId($snapshot, 'away');
        $currentHomeId = trim((string) $game->resolvedStartingPitcherEspnId('home'));
        $currentAwayId = trim((string) $game->resolvedStartingPitcherEspnId('away'));

        return $capturedHomeId !== ''
            && $capturedAwayId !== ''
            && $currentHomeId !== ''
            && $currentAwayId !== ''
            && hash_equals($capturedHomeId, $currentHomeId)
            && hash_equals($capturedAwayId, $currentAwayId);
    }

    private function capturedPitcherId(PredictionFeatureSnapshot|CanonicalShadowSnapshot $snapshot, string $side): string
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
        PredictionFeatureSnapshot|CanonicalShadowSnapshot $snapshot,
        array $output,
        array $receipt,
        callable $assertOwned,
    ): int {
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
        $inferenceRun = null;
        $intendedOutputs = [];

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
            $identity = ['model_artifact_id' => $artifact->id,
                ...($snapshot instanceof CanonicalShadowSnapshot ? $snapshot->identity() : ['prediction_feature_snapshot_id' => $snapshot->id]),
                'market_type' => $marketType];
            if (ShadowModelOutput::query()->where($identity)->exists()) {
                continue;
            }
            if ($inferenceRun === null) {
                $assertOwned();
                $inferenceRun = ModelRun::query()->create([
                    'id' => $receipt['id'], 'sport' => 'mlb', 'run_type' => 'shadow_inference',
                    'model_version' => $artifact->model_version, 'feature_version' => $artifact->feature_version,
                    'blend_version' => 'shadow-inference-v1', 'config_hash' => $receipt['config_hash'], 'code_version' => $receipt['code_version'],
                    'parameters' => ['model_artifact_id' => $artifact->id, 'artifact_hash' => $artifact->artifact_hash],
                    'metadata' => ['run_type' => 'shadow_inference', 'parameters' => ['model_artifact_id' => $artifact->id, 'artifact_hash' => $artifact->artifact_hash]], 'status' => 'completed',
                    'started_at' => now(), 'completed_at' => now(),
                ]);
                if (! $inferenceRun->exists || ! $inferenceRun->wasRecentlyCreated || $inferenceRun->id !== $receipt['id']
                    || $inferenceRun->status !== 'completed' || $inferenceRun->sport !== 'mlb' || $inferenceRun->run_type !== 'shadow_inference'
                    || data_get($inferenceRun->parameters, 'model_artifact_id') !== $artifact->id
                    || data_get($inferenceRun->parameters, 'artifact_hash') !== $artifact->artifact_hash) {
                    throw new \RuntimeException('Inference receipt persistence was cancelled or changed identity.');
                }
            }
            $assertOwned();
            $this->assertPersistedHeader('model_runs', $receipt['id'], [
                'id' => $receipt['id'], 'sport' => 'mlb', 'run_type' => 'shadow_inference', 'status' => 'completed',
                'model_version' => $artifact->model_version, 'feature_version' => $artifact->feature_version,
                'blend_version' => 'shadow-inference-v1', 'config_hash' => $receipt['config_hash'], 'code_version' => $receipt['code_version'],
            ]);
            $promoted = $artifact->isPromotedForMarket($marketType)
                && $artifact->promoted_at !== null
                && $artifact->promoted_at->lessThanOrEqualTo(now());
            $assertOwned();
            $shadow = ShadowModelOutput::query()->firstOrCreate(
                [
                    'model_artifact_id' => $artifact->id,
                    ...($snapshot instanceof CanonicalShadowSnapshot ? $snapshot->identity() : ['prediction_feature_snapshot_id' => $snapshot->id]),
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
                        'canonical_lineage' => $snapshot instanceof CanonicalShadowSnapshot ? $snapshot->lineage : null,
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
            if (! $shadow->exists || ! $shadow->wasRecentlyCreated || $shadow->inference_run_id !== $receipt['id']
                || $shadow->model_artifact_id !== $artifact->id || (int) $shadow->game_id !== (int) $snapshot->game_id
                || $shadow->market_type !== $marketType || $shadow->status !== ($promoted ? 'promoted_shadow' : 'shadow')
                || ($snapshot instanceof CanonicalShadowSnapshot
                    ? (int) $shadow->canonical_prediction_id !== (int) $snapshot->id
                    : (int) $shadow->prediction_feature_snapshot_id !== (int) $snapshot->id)) {
                throw new \RuntimeException('Inference output persistence was cancelled or changed identity.');
            }
            $assertOwned();
            $expectedOutput = [
                'id' => $shadow->id, 'inference_run_id' => $receipt['id'], 'model_artifact_id' => $artifact->id,
                'sport' => 'mlb', 'game_table' => 'mlb_games', 'game_id' => $snapshot->game_id,
                'prediction_table' => $snapshot->prediction_table, 'prediction_id' => $snapshot->prediction_id,
                'prediction_feature_snapshot_id' => $snapshot instanceof CanonicalShadowSnapshot ? null : $snapshot->id,
                'canonical_prediction_id' => $snapshot instanceof CanonicalShadowSnapshot ? $snapshot->id : null,
                'market_type' => $marketType, 'status' => $promoted ? 'promoted_shadow' : 'shadow',
                'baseline_output' => (float) $baseline, 'challenger_output' => (float) $challenger, 'output_delta' => (float) ($challenger - $baseline),
            ];
            $this->assertPersistedHeader('shadow_model_outputs', $shadow->id, $expectedOutput);
            $intendedOutputs[$shadow->id] = $expectedOutput;
            $created++;
        }

        foreach ($intendedOutputs as $outputId => $expectedOutput) {
            $assertOwned();
            $this->assertPersistedHeader('shadow_model_outputs', $outputId, $expectedOutput);
        }
        if ($inferenceRun !== null) {
            $assertOwned();
            $this->assertPersistedHeader('model_runs', $receipt['id'], [
                'id' => $receipt['id'], 'sport' => 'mlb', 'run_type' => 'shadow_inference', 'status' => 'completed',
                'model_version' => $artifact->model_version, 'feature_version' => $artifact->feature_version,
                'blend_version' => 'shadow-inference-v1', 'config_hash' => $receipt['config_hash'], 'code_version' => $receipt['code_version'],
            ]);
        }

        return $created;
    }

    /** Current primary headers witness actual persisted rows, not only Eloquent result flags. */
    private function assertPersistedHeader(string $table, mixed $id, array $expected): void
    {
        $connection = (new ModelRun)->getConnection();
        $select = $invalid = [];
        foreach (array_keys($expected) as $field) {
            $column = $connection->getQueryGrammar()->wrap($field);
            $bytes = $connection->getDriverName() === 'mysql' ? 'OCTET_LENGTH('.$column.')' : 'length(CAST('.$column.' AS BLOB))';
            $select[] = 'CASE WHEN COALESCE('.$bytes.',0) <= 4096 THEN '.$column.' ELSE NULL END AS '.$column;
            $invalid[] = 'COALESCE('.$bytes.',0) > 4096';
        }
        $current = $connection->table($table)->useWritePdo()->where('id', $id)->lockForUpdate()
            ->selectRaw(implode(', ', $select).', CASE WHEN ('.implode(' OR ', $invalid).') THEN 1 ELSE 0 END AS _oversized')->first();
        if ($current === null || (int) $current->_oversized !== 0) {
            throw new \RuntimeException('Intended inference receipt/output is not currently persisted.');
        }
        foreach ($expected as $field => $value) {
            $actual = $current->$field;
            if (is_float($value)) {
                // These three storage columns are DECIMAL(12,6) on MySQL and
                // NUMERIC affinity on SQLite. Compare the engine's conversion,
                // never an epsilon or a rounded scientific/model input.
                if (! in_array($field, ['baseline_output', 'challenger_output', 'output_delta'], true)) {
                    throw new \RuntimeException('Unsupported numeric inference header.');
                }
                $type = $connection->getDriverName() === 'mysql' ? 'DECIMAL(12,6)' : 'NUMERIC';
                $stored = $connection->scalar('SELECT CAST(? AS '.$type.')', [$value], false);
                $matches = $connection->getDriverName() === 'mysql'
                    ? (string) $actual === (string) $stored
                    : is_numeric($actual) && (float) $actual === (float) $stored;
                if ($actual === null || ! $matches) {
                    throw new \RuntimeException('Current persisted inference numeric representation differs from its intended storage value.');
                }
            } elseif (($actual === null) !== ($value === null) || ($value !== null && (string) $actual !== (string) $value)) {
                throw new \RuntimeException('Current persisted inference receipt/output changed its intended header.');
            }
        }
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
