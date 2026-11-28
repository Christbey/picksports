<?php

namespace App\Services\Predictions;

use App\Models\CalculationRelease;
use App\Models\CalculationReleaseComponent;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\FeatureSchema;
use App\Models\MLB\PeriodFeatureSnapshot;
use App\Models\ModelRun;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Models\PredictionMarket;
use App\Models\PredictionPublicationEffect;
use App\Models\PredictionReviewRevision;
use App\Models\SportEvent;
use App\Services\MLB\MlbModelRunAdmissionGuard;
use Closure;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Current-row admission for instance writes, including quiet and numeric methods. */
final class CoreEvidenceMutationGuard
{
    private const SHADOW_EVALUATIONS = ['nfl-prop-shadow-v1', 'nfl-game-simulation-shadow-v1'];

    private const SHADOW_SNAPSHOTS = ['nfl-prop-shadow-v1', 'nfl-game-simulation-shadow-v1', 'nfl-prop-probability-v1'];

    private const NATIVE_IDENTITY = ['public_id', 'sport_event_id', 'calculation_run_id', 'feature_schema_id',
        'dataset_export_manifest_id', 'model_run_id', 'model_artifact_id', 'sport', 'revision', 'supersedes_prediction_id',
        'phase', 'detail_source', 'detail_sport', 'detail_id', 'model_version', 'feature_version', 'blend_version'];

    public function run(Model $model, string $operation, array $patch, Closure $write): mixed
    {
        if (! $model->exists && ! $model instanceof PredictionMarket && ! $model instanceof CalculationReleaseComponent && ! $model instanceof ModelRun) {
            return $write();
        }
        if ($model->exists && array_key_exists($model->getKeyName(), $patch)) {
            throw new LogicException('Persisted evidence primary keys cannot change.');
        }
        $key = $model->getRawOriginal($model->getKeyName()) ?? $model->getKey();
        $connection = $model->getConnection();

        return $connection->transaction(function () use ($model, $key, $operation, $patch, $write): mixed {
            $needsHint = $model instanceof CanonicalPrediction || $model instanceof PredictionMarket || $model instanceof CalculationReleaseComponent;
            $hint = $model->exists && $needsHint ? $model->newModelQuery()->find($key) : null;
            if ($model->exists && $needsHint && $hint === null) {
                throw new LogicException('Evidence row is missing; retry the owning operation.');
            }
            $parents = $this->lockParents($model, $hint, $operation, $patch);
            $current = $model->exists
                ? match (true) {
                    $model instanceof ModelRun => MlbModelRunAdmissionGuard::current($model, $key),
                    $model instanceof PeriodFeatureSnapshot => $this->currentPeriodCapture($model, $key),
                    default => $model->newModelQuery()->whereKey($key)->lockForUpdate()->first(),
                }
            : null;
            if ($model->exists && $current === null) {
                throw new LogicException('Evidence row changed during discovery; retry the owning operation.');
            }
            $this->revalidateParent($model, $hint, $current);
            $this->admit($model, $current, $operation, $patch, $parents);

            return $write();
        });
    }

    /** Leaf mutation guard needs only classification, never the captured feature body. */
    private function currentPeriodCapture(PeriodFeatureSnapshot $model, mixed $key): ?PeriodFeatureSnapshot
    {
        $connection = $model->getConnection();
        $bytes = match ($connection->getDriverName()) {
            'mysql' => 'COALESCE(OCTET_LENGTH(CAST(forecast_source AS BINARY)),0)',
            'sqlite' => 'COALESCE(LENGTH(CAST(forecast_source AS BLOB)),0)',
            default => throw new LogicException('MLB period capture mutation dialect is unsupported.'),
        };
        $current = $model->newModelQuery()->useWritePdo()->select('id')
            ->selectRaw('CASE WHEN '.$bytes.' <= 16 THEN forecast_source ELSE NULL END AS forecast_source')
            ->selectRaw('CASE WHEN '.$bytes.' <= 16 THEN 0 ELSE 1 END AS source_oversized')
            ->whereKey($key)->lockForUpdate()->first();
        if ($current !== null && (int) $current->getAttribute('source_oversized') !== 0) {
            throw new LogicException('MLB period capture classification exceeds its scalar budget.');
        }

        return $current;
    }

    /** Parent discovery is a hint only; callers holding opposite locks are not generically certified. */
    private function lockParents(Model $model, ?Model $hint, string $operation, array $patch): array
    {
        if ($model instanceof CalculationReleaseComponent) {
            $ids = $this->ownerIds($model, $hint, 'calculation_release_id', $operation, $patch);
            $this->sameConnection($model, new CalculationRelease);
            $parents = CalculationRelease::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($parents->count() !== count($ids)) {
                throw new LogicException('Calculation component requires known same-connection release owners.');
            }

            return $parents->all();
        }
        if ($model instanceof PredictionMarket) {
            $ids = $this->ownerIds($model, $hint, 'prediction_id', $operation, $patch);
            $this->sameConnection($model, new CanonicalPrediction, new SportEvent);
            $hints = CanonicalPrediction::query()->whereKey($ids)->get()->keyBy('id');
            if ($hints->count() !== count($ids)) {
                throw new LogicException('Prediction market requires known prediction owners.');
            }
            $events = $this->ids($hints->pluck('sport_event_id')->all());
            if (SportEvent::query()->whereKey($events)->orderBy('id')->lockForUpdate()->get()->count() !== count($events)) {
                throw new LogicException('Prediction market event owner is unavailable.');
            }
            $parents = CanonicalPrediction::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($ids as $id) {
                if (! isset($parents[$id]) || $parents[$id]->sport_event_id !== $hints[$id]->sport_event_id) {
                    throw new LogicException('Prediction owner changed during discovery; retry the owning transaction.');
                }
            }

            return $parents->all();
        }
        if ($model instanceof CanonicalPrediction && $hint !== null) {
            $this->sameConnection($model, new SportEvent);
            $ids = $this->ids(array_merge([$hint->sport_event_id], array_key_exists('sport_event_id', $patch) ? [$patch['sport_event_id']] : []));
            if (SportEvent::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->count() !== count($ids)) {
                throw new LogicException('Canonical prediction event owner is unavailable.');
            }
        }

        return [];
    }

    private function revalidateParent(Model $model, ?Model $hint, ?Model $current): void
    {
        $field = match (true) {
            $model instanceof PredictionMarket => 'prediction_id',
            $model instanceof CalculationReleaseComponent => 'calculation_release_id',
            $model instanceof CanonicalPrediction => 'sport_event_id',
            default => null,
        };
        if ($field !== null && $current !== null && $hint?->getAttribute($field) !== $current->getAttribute($field)) {
            throw new LogicException('Evidence parent changed during discovery; retry the owning transaction.');
        }
    }

    private function admit(Model $model, ?Model $current, string $operation, array $patch, array $parents): void
    {
        if ($model instanceof PeriodFeatureSnapshot && $current !== null) {
            $source = $current->getRawOriginal('forecast_source');
            if (! in_array($source, ['legacy', 'canonical'], true)) {
                throw new LogicException('MLB period capture source identity is unavailable.');
            }
            if (array_key_exists('forecast_source', $patch) && $patch['forecast_source'] !== $source) {
                throw new LogicException('Existing MLB period captures cannot change source identity.');
            }
            if ($source === 'canonical' && ($operation === 'delete' || $patch !== [])) {
                throw new LogicException('Native MLB period features are immutable.');
            }

            return;
        }
        if ($model instanceof ModelRun) {
            foreach (['sport', 'run_type'] as $classification) {
                if (array_key_exists($classification, $patch) && ! is_string($patch[$classification])) {
                    throw new LogicException('Model run classification updates require literal strings.');
                }
            }
            $wasCbbEpa = $current !== null && $current->sport === 'cbb' && $current->run_type === 'play_epa';
            $willBeCbbEpa = ($patch['sport'] ?? $current?->sport ?? $model->sport) === 'cbb'
                && ($patch['run_type'] ?? $current?->run_type ?? $model->run_type) === 'play_epa';
            if ($current !== null && (($wasCbbEpa && ($operation === 'delete' || $patch !== [])) || (! $wasCbbEpa && $willBeCbbEpa))) {
                throw new LogicException('CBB EPA calculation receipts are immutable; existing runs cannot acquire receipt authority.');
            }
            MlbModelRunAdmissionGuard::admit($model, $current, $operation, $patch);

            return;
        }
        if ($model instanceof CalculationReleaseComponent) {
            foreach ($parents as $release) {
                if ($release->status !== 'draft') {
                    throw new LogicException('Components of a released calculation release are immutable.');
                }
            }

            return;
        }
        if ($model instanceof PredictionMarket) {
            foreach ($parents as $prediction) {
                if ($prediction->calculation_run_id !== null && ($operation === 'delete'
                    || in_array($prediction->publication_state, ['published', 'superseded', 'withdrawn'], true))) {
                    throw new LogicException($operation === 'delete' ? 'Markets on canonical prediction revisions cannot be deleted.' : 'Markets on released canonical predictions are immutable.');
                }
            }

            return;
        }
        if ($current === null || ($operation !== 'delete' && $patch === [])) {
            return;
        }
        if ($model instanceof PredictionPublicationEffect) {
            if ($operation === 'delete') {
                throw new LogicException('Publication delivery receipts cannot be deleted.');
            }
            if (array_intersect(array_keys($patch), ['canonical_prediction_id', 'effect', 'payload']) !== []) {
                throw new LogicException('Publication effect identity and admission payload are immutable.');
            }
            foreach (['canonical_prediction_id', 'effect'] as $field) {
                if ((string) $model->getOriginal($field) !== (string) $current->getAttribute($field)) {
                    throw new LogicException('Publication effect identity changed; retry the owning operation.');
                }
            }
            $hasher = app(CanonicalPayloadHasher::class);
            if (! hash_equals($hasher->hash($model->getOriginal('payload')), $hasher->hash($current->payload))) {
                throw new LogicException('Publication effect admission payload changed; retry the owning operation.');
            }
            if (in_array($current->state, ['completed', 'cancelled', 'exhausted'], true)) {
                throw new LogicException('Terminal publication effect receipts are immutable.');
            }

            return;
        }
        if ($model instanceof PredictionFeatureSnapshot && $current instanceof PredictionFeatureSnapshot) {
            $metadata = array_key_exists('model_metadata', $patch)
                ? $this->requestedAttribute($model, 'model_metadata', $patch, $operation) : $current->model_metadata;
            $sport = array_key_exists('sport', $patch)
                ? $this->requestedAttribute($model, 'sport', $patch, $operation) : $current->sport;
            $wasMlbMarked = data_get($current->model_metadata, 'mlb_capture_integrity.schema') === 'mlb-entry-capture-v1';
            $willBeMlbMarked = data_get($metadata, 'mlb_capture_integrity.schema') === 'mlb-entry-capture-v1';
            if ($operation !== 'delete' && $willBeMlbMarked && (! $wasMlbMarked
                || (strtolower(trim((string) $sport)) === 'mlb' && strtolower(trim((string) $current->sport)) !== 'mlb'))) {
                throw new LogicException('Existing legacy snapshots cannot acquire MLB capture certification.');
            }
            $wasMarked = data_get($current->model_metadata, 'snapshot_integrity.schema') === 'cfb-entry-payload-v2';
            $willBeMarked = data_get($metadata, 'snapshot_integrity.schema') === 'cfb-entry-payload-v2';
            if ($operation !== 'delete' && $willBeMarked && (! $wasMarked
                || (strtolower(trim((string) $sport)) === 'cfb' && strtolower(trim((string) $current->sport)) !== 'cfb'))) {
                throw new LogicException('Existing legacy snapshots cannot acquire CFB capture certification.');
            }
        }

        if ($model instanceof EventInputSnapshot || $model instanceof FeatureSchema || $model instanceof PredictionReviewRevision
            || ($model instanceof PredictionEvaluation && ($current->canonical_prediction_id !== null || in_array($current->blend_version, self::SHADOW_EVALUATIONS, true)))
            || ($model instanceof PredictionFeatureSnapshot && $this->isImmutableFeatureSnapshot($current))) {
            $message = match (true) {
                $model instanceof EventInputSnapshot => 'Event input snapshots are immutable.',
                $model instanceof FeatureSchema => 'Feature schemas are immutable.',
                $model instanceof PredictionReviewRevision => 'Prediction review revisions are immutable.',
                $model instanceof PredictionFeatureSnapshot => match (strtolower(trim((string) $current->sport))) {
                    'cfb' => 'CFB captured snapshots are immutable.', 'mlb' => 'MLB captured snapshots are immutable.', default => 'NFL shadow snapshots are immutable.',
                },
                $current->canonical_prediction_id !== null => 'Canonical prediction evaluations are immutable.',
                default => 'NFL shadow evaluations are immutable.',
            };
            throw new LogicException($message);
        }
        if ($model instanceof CalculationRun) {
            if ($operation === 'delete' || $current->status === 'succeeded'
                || array_intersect(array_keys($patch), ['sport_event_id', 'event_input_snapshot_id', 'calculation_release_id', 'phase', 'trigger', 'idempotency_key']) !== []) {
                throw new LogicException('Calculation run identity and successful results are immutable.');
            }

            return;
        }
        if ($model instanceof CalculationRelease) {
            $this->admitRelease($current, $operation, $patch);
        } elseif ($model instanceof CanonicalPrediction && $current->calculation_run_id !== null) {
            $this->admitPrediction($current, $operation, $patch);
        }
    }

    public function isImmutableFeatureSnapshot(PredictionFeatureSnapshot $snapshot): bool
    {
        return in_array($snapshot->blend_version, self::SHADOW_SNAPSHOTS, true)
            || (strtolower(trim((string) $snapshot->sport)) === 'cfb'
                && data_get($snapshot->model_metadata, 'snapshot_integrity.schema') === 'cfb-entry-payload-v2')
            || (strtolower(trim((string) $snapshot->sport)) === 'mlb'
                && data_get($snapshot->model_metadata, 'mlb_capture_integrity.schema') === 'mlb-entry-capture-v1');
    }

    private function requestedAttribute(Model $model, string $field, array $patch, string $operation): mixed
    {
        if ($operation !== 'numeric') {
            return $model->getAttribute($field);
        }
        $value = $patch[$field];
        // Numeric extras are sent directly to the query builder; JSON strings are SQL JSON objects,
        // even though forceFill on the in-memory model would encode that string again.
        if ($field === 'model_metadata' && is_string($value)) {
            try {
                return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $value;
            }
        }

        return $value;
    }

    private function admitRelease(CalculationRelease $current, string $operation, array $patch): void
    {
        if ($current->status === 'draft') {
            return;
        }
        if ($operation === 'delete' || array_diff(array_keys($patch), ['status', 'retired_at', 'invalidated_at', 'invalidation_reason', 'updated_at']) !== []) {
            throw new LogicException('Approved calculation release definitions are immutable.');
        }
        if ($current->status === 'invalidated' && array_diff(array_keys($patch), ['updated_at']) !== []) {
            throw new LogicException('Invalidated calculation releases are immutable.');
        }
        $next = $patch['status'] ?? $current->status;
        $allowed = ['approved' => ['approved', 'retired', 'invalidated'], 'retired' => ['retired', 'invalidated'], 'invalidated' => ['invalidated']];
        if (! in_array($next, $allowed[$current->status] ?? [], true)
            || (array_key_exists('retired_at', $patch) && ! in_array($next, ['retired', 'invalidated'], true))) {
            throw new LogicException('Invalid calculation release transition.');
        }
    }

    private function admitPrediction(CanonicalPrediction $current, string $operation, array $patch): void
    {
        if ($operation === 'delete' || array_intersect(array_keys($patch), self::NATIVE_IDENTITY) !== []) {
            throw new LogicException('Native canonical prediction identity is immutable.');
        }
        if (in_array($current->publication_state, ['superseded', 'withdrawn'], true)) {
            if (array_diff(array_keys($patch), ['updated_at']) !== []) {
                throw new LogicException('Released canonical prediction revisions are immutable.');
            }

            return;
        }
        if ($current->publication_state === 'draft') {
            $next = $patch['publication_state'] ?? 'draft';
            if (! in_array($next, ['draft', 'published', 'withdrawn'], true)
                || ($next === 'withdrawn' && ($patch['withdrawn_at'] ?? $current->withdrawn_at) === null)
                || array_key_exists('superseded_at', $patch)
                || (array_key_exists('withdrawn_at', $patch) && $next !== 'withdrawn')) {
                throw new LogicException('Invalid native draft publication transition.');
            }

            return;
        }
        if ($current->publication_state !== 'published') {
            throw new LogicException('Native canonical prediction has an inconsistent publication state.');
        }
        if (array_diff(array_keys($patch), ['publication_state', 'withdrawn_at', 'superseded_at', 'updated_at']) !== []) {
            throw new LogicException('Published canonical prediction revisions are immutable.');
        }
        $next = $patch['publication_state'] ?? $current->publication_state;
        if (! in_array($next, ['published', 'superseded', 'withdrawn'], true)
            || ($next !== 'published' && ($patch[$next.'_at'] ?? $current->getAttribute($next.'_at')) === null)
            || (array_key_exists('withdrawn_at', $patch) && $next !== 'withdrawn')
            || (array_key_exists('superseded_at', $patch) && $next !== 'superseded')) {
            throw new LogicException("Invalid canonical prediction transition from {$current->publication_state} to {$next}.");
        }
    }

    private function ownerIds(Model $model, ?Model $hint, string $field, string $operation, array $patch): array
    {
        $values = $hint === null ? [] : [$hint->getAttribute($field)];
        if ($operation !== 'delete') {
            $values[] = array_key_exists($field, $patch) ? $patch[$field] : $model->getAttribute($field);
        }
        if (in_array(null, $values, true)) {
            throw new LogicException('Evidence child cannot detach from its owner.');
        }

        return $this->ids($values);
    }

    private function ids(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        foreach ($ids as $id) {
            if (! is_numeric($id) || (int) $id < 1 || (string) (int) $id !== (string) $id) {
                throw new LogicException('Evidence owner identity must be a positive integer.');
            }
        }
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function sameConnection(Model $model, Model ...$parents): void
    {
        foreach ($parents as $parent) {
            if ($parent->getConnection()->getName() !== $model->getConnection()->getName()) {
                throw new LogicException('Evidence owners must share one resolved database connection.');
            }
        }
    }
}
