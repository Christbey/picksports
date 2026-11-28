<?php

namespace App\Services\Predictions;

use App\Models\MLB\Game as MlbGame;
use App\Models\ModelRun;
use App\Models\NFL\Game;
use App\Models\PredictionFeatureSnapshot;
use App\Services\CFB\CfbSnapshotCaptureIntegrity;
use App\Services\ML\ShadowModelOutputRecorder;
use App\Services\MLB\MlbSnapshotCaptureIntegrity;
use App\Services\NFL\GameDepthChartLinkRecorder;
use App\Support\MLB\MlbGameStart;
use App\Support\Odds\MarketSpread;
use App\Support\Sports\LegacyTimestampCarrier;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PredictionFeatureSnapshotRecorder
{
    /**
     * @param  array<string, mixed>  $predictionData
     * @param  array<string, mixed>  $snapshot
     */
    public function record(Model $prediction, Model $game, string $sport, array $predictionData, array $snapshot = [], ?array $preparedRunContext = null): PredictionFeatureSnapshot
    {
        if ($sport === 'mlb' && $game instanceof MlbGame) {
            if (($snapshot['run_type'] ?? 'prediction') !== 'historical_reconstruction' && $game->getConnection()->transactionLevel() === 0) {
                return $this->recordCurrentMlb($prediction, $game, $predictionData, $snapshot, $preparedRunContext);
            }
            // Do not introduce Game-after-Prediction locks into an unknown inherited/reconstruction transaction.
            $snapshot['pregame_safe'] = false;
            $snapshot['point_in_time_verified'] = false;
            $snapshot['availability_status'] = ($snapshot['run_type'] ?? 'prediction') === 'historical_reconstruction'
                ? 'reconstructed_now' : 'capture_boundary_unverified';
        }

        return $this->recordLocal($prediction, $game, $sport, $predictionData, $snapshot, preparedRunContext: $preparedRunContext);
    }

    /** Explicit known Game-first entry. Arbitrary caller-owned opposite locks are not supported. */
    public function recordCurrentMlb(Model $prediction, MlbGame $game, array $predictionData, array $snapshot = [], ?array $preparedRunContext = null): PredictionFeatureSnapshot
    {
        $connection = $game->getConnection();
        foreach ([$prediction, new PredictionFeatureSnapshot, new ModelRun] as $model) {
            if ($model->getConnection()->getName() !== $connection->getName()) {
                throw new InvalidArgumentException('MLB capture requires one coherent resolved database connection.');
            }
        }
        if (! $prediction->exists || ! $game->exists || $prediction->getTable() !== 'mlb_predictions') {
            throw new InvalidArgumentException('MLB capture requires persisted matching prediction and Game identities.');
        }

        return $connection->transaction(function () use ($prediction, $game, $predictionData, $snapshot, $preparedRunContext): PredictionFeatureSnapshot {
            $currentGame = $game->newQuery()->lockForUpdate()->findOrFail($game->getKey());
            if (MlbGameStart::context($currentGame) !== MlbGameStart::context($game) || $currentGame->status !== $game->status) {
                throw new InvalidArgumentException('MLB capture Game context changed; retry the complete calculation.');
            }
            $currentPrediction = $prediction->newQuery()->lockForUpdate()->findOrFail($prediction->getKey());
            if ((int) $currentPrediction->game_id !== (int) $currentGame->getKey()) {
                throw new InvalidArgumentException('MLB capture prediction does not belong to its current locked Game.');
            }

            return $this->recordLocal($currentPrediction, $currentGame, 'mlb', $predictionData, $snapshot, true, $preparedRunContext);
        });
    }

    private function recordLocal(Model $prediction, Model $game, string $sport, array $predictionData, array $snapshot, bool $captureMlb = false, ?array $preparedRunContext = null): PredictionFeatureSnapshot
    {
        $modelVersion = (string) ($snapshot['model_version'] ?? $predictionData['model_version'] ?? 'rules-v1');
        $featureVersion = (string) ($snapshot['feature_version'] ?? $predictionData['feature_version'] ?? 'core-v1');
        $blendVersion = (string) ($snapshot['blend_version'] ?? $predictionData['blend_version'] ?? 'baseline-v1');

        $features = $snapshot['features'] ?? $this->extractFeatures($predictionData);
        $outputs = $snapshot['outputs'] ?? $this->extractOutputs($predictionData);
        $marketContext = $snapshot['market_context'] ?? $this->extractMarketContext($predictionData);
        $modelMetadata = $snapshot['model_metadata']
            ?? (is_array($predictionData['model_metadata'] ?? null) ? $predictionData['model_metadata'] : null);
        $generatedAt = $this->snapshotInstant($snapshot['generated_at'] ?? now());
        $provenance = app(SnapshotProvenanceResolver::class)->resolve($game, $snapshot, $generatedAt);
        if ($sport === 'cfb' && $game instanceof \App\Models\CFB\Game) {
            $modelMetadata = is_array($modelMetadata) ? $modelMetadata : [];
            // Always derive this marker here; caller metadata cannot manufacture capture certification.
            $modelMetadata['snapshot_integrity'] = app(CfbSnapshotCaptureIntegrity::class)->marker(
                $game, $features, $outputs, $marketContext, $provenance['game_start_at'],
            );
        }

        if ($sport === 'mlb' && $game instanceof MlbGame) {
            $modelMetadata = is_array($modelMetadata) ? $modelMetadata : [];
            unset($modelMetadata['mlb_capture_integrity']);
            if ($captureMlb) {
                $marker = app(MlbSnapshotCaptureIntegrity::class)->marker($prediction, $game, $features, $outputs, $marketContext, $generatedAt, $provenance, ['model' => $modelVersion, 'feature' => $featureVersion, 'blend' => $blendVersion]);
                $modelMetadata['mlb_capture_integrity'] = $marker;
                $provenance['features_available_at'] = CarbonImmutable::parse($marker['available_at_utc']);
                $provenance['pregame_safe'] = $marker['qualified'];
                if (! $marker['qualified']) {
                    $provenance['availability_status'] = 'capture_unqualified';
                    $provenance['lineage_metadata']['point_in_time_verified'] = false;
                }
            }
        }

        // These columns are legacy application-wall TIMESTAMP(0) carriers, not UTC6 evidence.
        $generatedCarrier = LegacyTimestampCarrier::forInstant($generatedAt);
        $availableCarrier = LegacyTimestampCarrier::forInstant($this->snapshotInstant($provenance['features_available_at']));
        if ($generatedCarrier === null || $availableCarrier === null) {
            throw new InvalidArgumentException('Snapshot generation/availability time cannot be represented by its legacy timestamp carrier.');
        }
        $start = $provenance['game_start_at'];
        $startCarrier = $start === null ? null : LegacyTimestampCarrier::forInstant($this->snapshotInstant($start));
        if ($start !== null && $startCarrier === null) {
            $provenance['pregame_safe'] = false;
            $provenance['availability_status'] = 'game_start_unrepresentable';
            $provenance['lineage_metadata']['point_in_time_verified'] = false;
            $provenance['lineage_metadata']['observed_before_game_start'] = false;
        }
        $provenance['game_start_at'] = $startCarrier;
        $provenance['features_available_at'] = $availableCarrier;

        $modelRun = app(ModelRunRecorder::class)->forPrediction(
            $sport,
            $modelVersion,
            $featureVersion,
            $blendVersion,
            [
                'run_type' => (string) ($snapshot['run_type'] ?? 'prediction'),
                'parameters' => $snapshot['run_parameters'] ?? null,
            ],
            preparedContext: $preparedRunContext,
        );
        $featureSnapshot = (new PredictionFeatureSnapshot)->getConnection()->transaction(fn (): PredictionFeatureSnapshot => PredictionFeatureSnapshot::query()->create([
            'sport' => $sport,
            'prediction_table' => $prediction->getTable(),
            'prediction_id' => (int) $prediction->getKey(),
            'game_id' => (int) ($prediction->game_id ?? $game->getKey()),
            'snapshot_run_id' => (string) Str::uuid(),
            'model_run_id' => $modelRun->id,
            'model_version' => $modelVersion,
            'feature_version' => $featureVersion,
            'blend_version' => $blendVersion,
            'features' => $features,
            'outputs' => $outputs,
            'market_context' => $marketContext,
            'model_metadata' => app(SnapshotMetadataStorage::class)->prepare($modelMetadata, $sport),
            'feature_hash' => hash('sha256', json_encode([
                'features' => $features,
                'outputs' => $outputs,
                'market_context' => $marketContext,
            ])),
            'generated_at' => $generatedCarrier,
            ...$provenance,
        ]));

        if ($sport === 'nfl' && $game instanceof Game) {
            app(GameDepthChartLinkRecorder::class)->record($game, $featureSnapshot);
        }

        app(ShadowModelOutputRecorder::class)->record($featureSnapshot);

        return $featureSnapshot;
    }

    private function snapshotInstant(mixed $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Snapshot timestamps require a valid explicit calendar date and clock.');
        }
        try {
            $instant = new DateTimeImmutable($value, new DateTimeZone((string) config('app.timezone', 'UTC')));
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
                throw new InvalidArgumentException('Snapshot timestamp contains an invalid calendar date or clock.');
            }

            return CarbonImmutable::instance($instant);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Snapshot timestamp contains an invalid calendar date or clock.', previous: $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $predictionData
     * @return array<string, mixed>
     */
    private function extractFeatures(array $predictionData): array
    {
        $excludedKeys = [
            'game_id',
            'predicted_spread',
            'predicted_total',
            'win_probability',
            'confidence_score',
            'actual_spread',
            'actual_total',
            'spread_error',
            'total_error',
            'winner_correct',
            'graded_at',
            'model_version',
            'feature_version',
            'blend_version',
            'model_metadata',
        ];

        return collect($predictionData)
            ->except($excludedKeys)
            ->filter(fn (mixed $value): bool => $value !== null)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $predictionData
     * @return array<string, mixed>
     */
    private function extractOutputs(array $predictionData): array
    {
        $predictedSpread = $this->nullableFloat($predictionData['predicted_spread'] ?? null);
        $predictedTotal = $this->nullableFloat($predictionData['predicted_total'] ?? null);
        $bookmakerHomeSpread = $this->nullableFloat($predictionData['vegas_spread'] ?? null);
        $marketSpread = $this->nullableFloat($predictionData['market_spread'] ?? null);

        if ($marketSpread === null && $bookmakerHomeSpread !== null) {
            $marketSpread = MarketSpread::bookmakerHomeLineToHomeMargin($bookmakerHomeSpread);
        }

        return [
            'baseline_predicted_spread' => $this->nullableFloat($predictionData['baseline_predicted_spread'] ?? $predictedSpread),
            'baseline_predicted_total' => $this->nullableFloat($predictionData['baseline_predicted_total'] ?? $predictedTotal),
            'bookmaker_home_spread' => $bookmakerHomeSpread,
            'market_spread' => $marketSpread,
            'market_total' => $this->nullableFloat($predictionData['market_total'] ?? null),
            'blended_predicted_spread' => $this->nullableFloat($predictionData['blended_predicted_spread'] ?? $predictedSpread),
            'blended_predicted_total' => $this->nullableFloat($predictionData['blended_predicted_total'] ?? $predictedTotal),
            'predicted_spread' => $predictedSpread,
            'predicted_total' => $predictedTotal,
            'win_probability' => $this->nullableFloat($predictionData['win_probability'] ?? null),
            'confidence_score' => $this->nullableFloat($predictionData['confidence_score'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $predictionData
     * @return array<string, mixed>|null
     */
    private function extractMarketContext(array $predictionData): ?array
    {
        $marketContext = array_filter([
            'vegas_spread' => $this->nullableFloat($predictionData['vegas_spread'] ?? null),
            'market_spread' => $this->nullableFloat($predictionData['market_spread'] ?? null),
            'market_total' => $this->nullableFloat($predictionData['market_total'] ?? null),
        ], fn (mixed $value): bool => $value !== null);

        return $marketContext === [] ? null : $marketContext;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
