<?php

namespace App\Services\ML;

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Str;
use RuntimeException;

class ShadowModelOutputRecorder
{
    public function record(PredictionFeatureSnapshot $snapshot): ?ShadowModelOutput
    {
        $owner = $this->currentSnapshotContext($snapshot);
        if ($owner === null) {
            return null;
        }
        $shadow = (array) data_get($snapshot->model_metadata, 'shadow_inference', []);
        $contexts = $this->shadowContexts($shadow);

        if ($contexts === []) {
            $calibration = (array) data_get($snapshot->model_metadata, 'win_probability_calibration', []);
            if (is_string($calibration['artifact_id'] ?? null)) {
                $contexts = [[
                    ...$calibration,
                    'baseline_output' => $calibration['baseline_win_probability'] ?? null,
                    'challenger_output' => $calibration['calibrated_win_probability'] ?? null,
                    'market_type' => 'win_probability',
                ]];
            }
        }

        if ($contexts === []) {
            return null;
        }
        // Context resolution may read a compacted/archive body. Do that before
        // opening this component's leaf-lock transaction; inherited callers may
        // already own a transaction, as before.
        $ids = [];
        foreach ($contexts as $context) {
            if (is_string($context['artifact_id'] ?? null) && Str::isUuid($context['artifact_id'])) {
                $ids[] = strtolower($context['artifact_id']);
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);

        return $owner['connection']->transaction(function () use ($snapshot, $contexts, $owner, $ids): ?ShadowModelOutput {
            if (! $this->ownsCurrentConnection($snapshot, $owner)) {
                throw new RuntimeException('Generic shadow recording connection changed before leaf admission.');
            }
            $current = $this->currentSnapshotContext($snapshot, true);
            if ($current === null) {
                return null;
            }
            if ($current['connection'] !== $owner['connection'] || $current['pdo'] !== $owner['pdo']) {
                throw new RuntimeException('Generic shadow recording resolved another current owner.');
            }
            $current['artifacts'] = [];
            if (! $current['mlb']) {
                // All artifact leaves are planned and locked in UUID order, while
                // processing below retains the caller's original context order.
                foreach ($ids as $id) {
                    $artifact = (new ModelArtifact)->setConnection($owner['connection']->getName())->newModelQuery()
                        ->useWritePdo()->whereKey($id)->lockForUpdate()->first();
                    if (! $this->ownsCurrentConnection($snapshot, $owner)) {
                        throw new RuntimeException('Generic shadow recording lost its leaf connection.');
                    }
                    if ($artifact !== null && ! $artifact->isDirty()) {
                        $current['artifacts'][strtolower((string) $artifact->getKey())] = $artifact;
                    }
                }
            }
            $firstOutput = null;
            foreach ($contexts as $context) {
                $output = $this->recordContext($snapshot, $context, $current);
                $firstOutput ??= $output;
            }
            if (! $this->ownsCurrentConnection($snapshot, $owner)) {
                throw new RuntimeException('Generic shadow recording connection changed during persistence.');
            }

            return $firstOutput;
        });
    }

    /** Current scalar classification only; this is never an inference-authority token. */
    private function currentSnapshotContext(PredictionFeatureSnapshot $snapshot, bool $lock = false): ?array
    {
        $literal = new PredictionFeatureSnapshot;
        if (! $snapshot->exists || $snapshot->getTable() !== $literal->getTable()
            || $snapshot->getKeyName() !== $literal->getKeyName()
            || (string) $snapshot->getKey() !== (string) $snapshot->getRawOriginal($literal->getKeyName())
            || filter_var($snapshot->getKey(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return null;
        }
        $connection = $snapshot->getConnection();
        $owner = ['connection' => $connection, 'pdo' => $connection->getPdo()];
        if (! $this->ownsCurrentConnection($snapshot, $owner)
            || ! in_array($connection->getDriverName(), ['mysql', 'sqlite'], true)) {
            return null;
        }
        $grammar = $connection->getQueryGrammar();
        $mysql = $connection->getDriverName() === 'mysql';
        $length = $mysql ? 'OCTET_LENGTH' : 'length';
        $cast = $mysql ? 'BINARY' : 'BLOB';
        $select = [];
        foreach (['id' => 20, 'sport' => 32, 'prediction_table' => 96, 'prediction_id' => 20, 'game_id' => 20, 'model_run_id' => 36] as $field => $limit) {
            $column = $grammar->wrap($field);
            $select[] = "CASE WHEN $length(CAST($column AS $cast)) <= $limit THEN $column ELSE NULL END AS $column";
        }
        $query = $connection->table($literal->getTable())->useWritePdo()->where('id', $snapshot->getKey())
            ->selectRaw(implode(', ', $select).', CASE WHEN sport = ? OR prediction_table = ? THEN 1 ELSE 0 END AS _mlb_role', ['mlb', 'mlb_predictions']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $header = $query->first();
        if ($header === null || ! is_string($header->sport) || $header->sport === '' || ! is_string($header->prediction_table)) {
            return null;
        }
        foreach (['sport', 'prediction_table', 'prediction_id', 'game_id', 'model_run_id'] as $field) {
            if ((string) $header->{$field} !== (string) $snapshot->getRawOriginal($field)
                || (string) $header->{$field} !== (string) $snapshot->getAttribute($field)) {
                return null;
            }
        }

        return [...$owner, 'header' => $header, 'mlb' => (int) $header->_mlb_role === 1
            || strtolower(trim($header->sport)) === 'mlb'];
    }

    private function ownsCurrentConnection(PredictionFeatureSnapshot $snapshot, array $owner): bool
    {
        $connection = $owner['connection'];
        foreach ([$snapshot, new PredictionFeatureSnapshot, new ModelArtifact, new ModelRun, new ShadowModelOutput] as $model) {
            if ($model->getConnection() !== $connection) {
                return false;
            }
        }

        return $connection->getRawPdo() === $owner['pdo'];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordContext(
        PredictionFeatureSnapshot $snapshot,
        array $context,
        array $owner,
    ): ?ShadowModelOutput {
        $artifactId = $context['artifact_id'] ?? null;
        if (! is_string($artifactId)) {
            return null;
        }

        if (! $this->ownsCurrentConnection($snapshot, $owner)) {
            return null;
        }
        if ($owner['mlb']) {
            // A derived pregame capture marker proves capture, not model execution.
            // Only the separate Event-first final-output coordinator may author NEW
            // MLB execution rows. Existing historical bookkeeping remains readable.
            $markets = $this->historicalMlbMarketTypes($context);
            if ($markets === []) {
                return null;
            }
            $row = $owner['connection']->table('shadow_model_outputs')->useWritePdo()
                ->where('prediction_feature_snapshot_id', $snapshot->getKey())
                ->where('model_artifact_id', $artifactId)->whereIn('market_type', $markets)
                ->where('sport', $owner['header']->sport)->where('game_id', $owner['header']->game_id)
                ->where('prediction_table', $owner['header']->prediction_table)
                ->where('prediction_id', $owner['header']->prediction_id)->orderBy('id')->value('id');

            return $row === null ? null : (new ShadowModelOutput)->setConnection($owner['connection']->getName())
                ->newModelQuery()->useWritePdo()->find($row);
        }
        $observations = $this->structuredObservations($context);
        if ($observations === []) {
            return null;
        }
        $artifact = $owner['artifacts'][strtolower($artifactId)] ?? null;
        if (! $this->ownsCurrentConnection($snapshot, $owner) || ! $artifact || $artifact->sport !== $owner['header']->sport
            || ! in_array($artifact->status, ['challenger', 'promotion_eligible', 'promoted'], true)) {
            return null;
        }

        $current = $this->currentSnapshotContext($snapshot, true);
        if ($current === null || $current['mlb'] || $artifact->sport !== $current['header']->sport) {
            return null;
        }
        $identity = $owner['connection']->table('model_artifacts')->useWritePdo()->where('id', $artifact->id)
            ->select(['id', 'sport', 'model_type', 'training_run_id', 'status'])->lockForUpdate()->first();
        if ($identity === null) {
            return null;
        }
        foreach (['id', 'sport', 'model_type', 'training_run_id', 'status'] as $field) {
            if ((string) $identity->{$field} !== (string) $artifact->getRawOriginal($field)) {
                return null;
            }
        }

        $inferenceRun = app(ModelRunRecorder::class)->forPrediction(
            sport: $snapshot->sport,
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

        $firstOutput = null;
        foreach ($observations as $marketType => $outputs) {
            $marketPromoted = $this->marketPromotedAtInference(
                $artifact,
                $context,
                $marketType,
                $snapshot->generated_at,
            );
            $existingId = ShadowModelOutput::query()->useWritePdo()
                ->where('model_artifact_id', $artifact->id)->where('prediction_feature_snapshot_id', $snapshot->id)
                ->where('market_type', $marketType)->value('id');
            $output = ShadowModelOutput::query()->firstOrCreate(
                [
                    'model_artifact_id' => $artifact->id,
                    'prediction_feature_snapshot_id' => $snapshot->id,
                    'market_type' => $marketType,
                ],
                [
                    'inference_run_id' => $inferenceRun->id,
                    'sport' => $snapshot->sport,
                    'game_table' => $snapshot->sport.'_games',
                    'game_id' => $snapshot->game_id,
                    'prediction_table' => $snapshot->prediction_table,
                    'prediction_id' => $snapshot->prediction_id,
                    'baseline_output' => $outputs['baseline'],
                    'challenger_output' => $outputs['challenger'],
                    'output_delta' => $outputs['delta'],
                    'status' => $marketPromoted ? 'promoted_shadow' : 'shadow',
                    'explanation' => [
                        'artifact_id' => $artifact->id,
                        'artifact_hash' => $context['artifact_hash'] ?? $artifact->artifact_hash,
                        'artifact_status' => $artifact->status,
                        'training_run_id' => $context['training_run_id'] ?? $artifact->training_run_id,
                        'model_run_id' => $context['model_run_id'] ?? $inferenceRun->id,
                        'config_hash' => $context['config_hash'] ?? $artifact->trainingRun?->config_hash,
                        'dataset_hash' => $context['dataset_hash'] ?? $artifact->dataset_hash,
                        'feature_hash' => $context['feature_hash'] ?? $snapshot->feature_hash,
                        'market_promoted' => $marketPromoted,
                        'market_promotion' => $context['market_promotion'] ?? null,
                        'multi_market_contract' => isset($context['baseline_outputs'])
                            && isset($context['challenger_outputs'])
                            && is_array($context['market_promotion'] ?? null),
                        'profile' => $context['profile'] ?? null,
                        'baseline_outputs' => $context['baseline_outputs'] ?? null,
                        'challenger_outputs' => $context['challenger_outputs'] ?? null,
                        'public_output_changed' => false,
                        'reason' => $context['reason'] ?? ($marketPromoted
                            ? 'promoted_artifact_shadow_audit'
                            : 'challenger_not_promoted'),
                    ],
                    'generated_at' => $snapshot->generated_at,
                ],
            );
            if (! $output->exists || $output->getKey() === null) {
                throw new RuntimeException('Generic shadow output persistence was cancelled.');
            }
            $persisted = $owner['connection']->table('shadow_model_outputs')->useWritePdo()
                ->where('id', $output->getKey())->where('model_artifact_id', $artifact->id)
                ->where('prediction_feature_snapshot_id', $snapshot->id)->where('market_type', $marketType)
                ->where('sport', $owner['header']->sport)->where('game_id', $owner['header']->game_id)
                ->where('prediction_table', $owner['header']->prediction_table)->where('prediction_id', $owner['header']->prediction_id)
                ->first(['id', 'inference_run_id', 'baseline_output', 'challenger_output', 'output_delta']);
            if ($persisted === null) {
                throw new RuntimeException('Generic shadow output no longer has its persisted owner.');
            }
            if ($existingId === null) {
                if ($persisted->inference_run_id !== $inferenceRun->id) {
                    throw new RuntimeException('Generic shadow output inference owner changed during persistence.');
                }
                foreach (['baseline_output' => 'baseline', 'challenger_output' => 'challenger', 'output_delta' => 'delta'] as $field => $key) {
                    if (! is_numeric($persisted->$field) || ! is_finite((float) $persisted->$field)
                        || (float) $persisted->$field !== $outputs[$key]) {
                        throw new RuntimeException('Generic shadow output differs from its staged requested values.');
                    }
                }
            }
            $firstOutput ??= $output;
        }

        return $firstOutput;
    }

    /**
     * @param  array<string, mixed>  $shadow
     * @return list<array<string, mixed>>
     */
    private function shadowContexts(array $shadow): array
    {
        $cohort = $shadow['cohort'] ?? null;
        if (is_array($cohort)) {
            return array_values(array_filter(
                $cohort,
                fn (mixed $context): bool => is_array($context)
                    && is_string($context['artifact_id'] ?? null),
            ));
        }

        return is_string($shadow['artifact_id'] ?? null) ? [$shadow] : [];
    }

    /** Market identity alone selects retained history; mutable values never author or refresh it. */
    private function historicalMlbMarketTypes(array $context): array
    {
        $markets = [];
        if (is_array($context['baseline_outputs'] ?? null)
            && is_array($context['challenger_outputs'] ?? null)
            && is_array($context['market_promotion'] ?? null)) {
            foreach (['win_probability' => 'win_probability', 'spread' => 'predicted_spread', 'total' => 'predicted_total'] as $market => $key) {
                if (array_key_exists($key, $context['baseline_outputs'])
                    || array_key_exists($key, $context['challenger_outputs'])) {
                    $markets[] = $market;
                }
            }
        }
        if ($markets !== []) {
            return $markets;
        }
        $type = $context['market_type'] ?? 'win_probability';
        if (! is_string($type)) {
            return [];
        }
        $type = ModelArtifact::normalizeMarketType($type);
        $type = $type === 'run_line' ? 'spread' : $type;

        return in_array($type, ['win_probability', 'spread', 'total'], true) ? [$type] : [];
    }

    /**
     * @param  array<string, mixed>  $shadow
     * @return array<string, array{baseline: float, challenger: float}>
     */
    private function structuredObservations(array $shadow): array
    {
        $baselineOutputs = $shadow['baseline_outputs'] ?? null;
        $challengerOutputs = $shadow['challenger_outputs'] ?? null;
        if (! is_array($baselineOutputs)
            || ! is_array($challengerOutputs)
            || ! is_array($shadow['market_promotion'] ?? null)) {
            return $this->legacyObservations(
                $shadow['baseline_output'] ?? null,
                $shadow['challenger_output'] ?? null,
                (string) ($shadow['market_type'] ?? 'win_probability'),
            );
        }

        $observations = [];
        foreach ([
            'win_probability' => 'win_probability',
            'spread' => 'predicted_spread',
            'total' => 'predicted_total',
        ] as $marketType => $outputKey) {
            $baseline = $baselineOutputs[$outputKey]
                ?? ($marketType === 'win_probability' ? ($shadow['baseline_output'] ?? null) : null);
            $challenger = $challengerOutputs[$outputKey]
                ?? ($marketType === 'win_probability' ? ($shadow['challenger_output'] ?? null) : null);
            $values = $this->observationValues($baseline, $challenger, $marketType);
            if ($values !== null) {
                $observations[$marketType] = $values;
            }
        }

        return $observations !== []
            ? $observations
            : $this->legacyObservations(
                $shadow['baseline_output'] ?? null,
                $shadow['challenger_output'] ?? null,
                (string) ($shadow['market_type'] ?? 'win_probability'),
            );
    }

    /**
     * @return array<string, array{baseline: float, challenger: float}>
     */
    private function legacyObservations(mixed $baseline, mixed $challenger, string $marketType): array
    {
        $marketType = match (ModelArtifact::normalizeMarketType($marketType)) {
            'run_line' => 'spread',
            default => ModelArtifact::normalizeMarketType($marketType),
        };
        $values = $this->observationValues($baseline, $challenger, $marketType);

        return $values === null ? [] : [$marketType => $values];
    }

    /** Validate observations, then normalize only their existing DECIMAL(12,6) storage representation. */
    private function observationValues(mixed $baseline, mixed $challenger, string $marketType): ?array
    {
        if (! in_array($marketType, ['win_probability', 'spread', 'total'], true)
            || ! is_numeric($baseline) || ! is_numeric($challenger)) {
            return null;
        }
        $baseline = (float) $baseline;
        $challenger = (float) $challenger;
        if (! is_finite($baseline) || ! is_finite($challenger)
            || ($marketType === 'win_probability' && ($baseline < 0 || $baseline > 1 || $challenger < 0 || $challenger > 1))
            || ($marketType === 'total' && ($baseline < 0 || $challenger < 0))) {
            return null;
        }
        $values = ['baseline' => round($baseline, 6), 'challenger' => round($challenger, 6),
            'delta' => round($challenger - $baseline, 6)];
        foreach ($values as $value) {
            if (! is_finite($value) || abs($value) > 999999.999999) {
                return null;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function marketPromotedAtInference(
        ModelArtifact $artifact,
        array $context,
        string $marketType,
        mixed $generatedAt,
    ): bool {
        $recordedPromotion = data_get($context, "market_promotion.{$marketType}");

        return $artifact->isPromotedForMarket($marketType)
            && ($recordedPromotion === null || (bool) $recordedPromotion)
            && $artifact->promoted_at !== null
            && $generatedAt !== null
            && $artifact->promoted_at->lessThanOrEqualTo($generatedAt);
    }
}
