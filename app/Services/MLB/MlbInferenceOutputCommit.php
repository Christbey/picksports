<?php

namespace App\Services\MLB;

use App\Application\Sports\ReadModels\CanonicalShadowSnapshot;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\MLB\Game;
use App\Models\ModelArtifact;
use App\Models\PredictionFeatureSnapshot;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\Predictions\CanonicalShadowInputs;
use App\Services\Predictions\ModelRunRecorder;
use App\Services\Sports\Elo\EloSqlClockPolicy;
use App\Support\MLB\MlbGameStart;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;
use WeakMap;

/** Qualified tabular shadow output only; this is not period training or promotion authority. */
final class MlbInferenceOutputCommit
{
    private WeakMap $prepared;

    public function __construct(private readonly MlbTabularArtifactAdmission $artifacts,
        private readonly MlbTabularSourceFence $sources, private readonly MlbArtifactCohortSerializer $cohorts,
        private readonly MlbArtifactCohortState $state, private readonly EloSqlClockPolicy $clocks)
    {
        $this->prepared = new WeakMap;
    }

    public function prepare(ModelArtifact $artifact, PredictionFeatureSnapshot|CanonicalShadowSnapshot $snapshot, array $features): object
    {
        $connection = $artifact->getConnection();
        if ($connection !== (new Game)->getConnection() || $connection->transactionLevel() !== 0 || $artifact->isDirty()) {
            throw new RuntimeException('Inference output requires a standalone coherent current connection.');
        }
        $clock = $this->clocks->capturePrimary($connection);
        $admission = $this->artifacts->admit($artifact);
        $target = $this->target($snapshot, false);
        if ($this->payload($target['snapshot']) !== $this->payload($snapshot)
            || $this->hash(app(MlbShadowFeatureBuilder::class)->build($target['snapshot'])) !== $this->hash($features)) {
            throw new RuntimeException('Inference features do not match the current target capture.');
        }
        $role = $this->role($admission['artifact'], false);
        $this->clocks->assertUnchanged($clock, $connection);
        // Stage every non-SQL receipt input before the child and final transaction.
        $recorder = app(ModelRunRecorder::class);
        $receipt = ['id' => (string) Str::uuid(), 'config_hash' => $recorder->configHash('mlb'), 'code_version' => $recorder->codeVersion()];
        MlbTabularJsonBudget::encode($receipt, 4096, 100, 4);
        $token = new \stdClass;
        $this->prepared[$token] = ['connection' => $connection, 'clock' => $clock, 'admission' => $admission,
            'target' => $target, 'role' => $role, 'snapshot' => $target['snapshot'], 'receipt' => $receipt];

        return $token;
    }

    /** Output creation and its successful run are one transaction; no filesystem/process I/O here. */
    public function commit(object $token, array $output, callable $write): int
    {
        $owned = $this->prepared[$token] ?? null;
        if ($owned === null) {
            throw new RuntimeException('Inference output requires its exact prepared coordinator instance.');
        }
        unset($this->prepared[$token]);
        $output = json_decode(MlbTabularJsonBudget::encode($output, 65536, 10000, 12), true, 12, JSON_THROW_ON_ERROR);
        $this->assertOutput($output, $owned['admission']);
        $connection = $owned['connection'];
        if ($connection !== (new Game)->getConnection() || $connection !== (new ModelArtifact)->getConnection()) {
            throw new RuntimeException('Inference output connection changed.');
        }
        $this->clocks->assertUnchanged($owned['clock'], $connection);
        $proof = $owned['admission']['proof']['dataset'];

        return $this->sources->runForInferenceTarget($proof, $owned['target']['game_id'], $owned['target']['event_id'],
            function () use ($owned, $proof, $connection, $output, $write): int {
                return $this->cohorts->run($connection, function () use ($owned, $proof, $connection, $output, $write): int {
                    $members = $this->state->read($connection);
                    if ($members->firstWhere('id', $owned['admission']['artifact']->id) === null) {
                        throw new RuntimeException('Inference artifact left its current cohort.');
                    }
                    $current = $this->artifacts->currentForOwnedMutation($owned['admission']['artifact'], $owned['admission']);
                    $role = $this->role($current['artifact'], true);
                    if (! hash_equals($owned['role']['hash'], $role['hash'])) {
                        throw new RuntimeException('Inference artifact market role changed before output storage.');
                    }
                    $target = $this->target($owned['snapshot'], true, $owned['target']);
                    if (! hash_equals($owned['target']['hash'], $target['hash'])) {
                        throw new RuntimeException('Inference target changed before output storage.');
                    }
                    $this->assertOwned($connection, $proof, $owned);
                    $count = $write($role['artifact'], $target['snapshot'], $output, $owned['receipt'], fn () => $this->assertOwned($connection, $proof, $owned));
                    $this->assertOwned($connection, $proof, $owned);
                    if (! hash_equals($owned['role']['hash'], $this->role($current['artifact'], true)['hash'])
                        || ! hash_equals($owned['target']['hash'], $this->target($owned['snapshot'], true, $owned['target'])['hash'])) {
                        throw new RuntimeException('Inference role or target changed during output persistence.');
                    }

                    return $count;
                });
            });
    }

    private function assertOutput(array $output, array $admission): void
    {
        $keys = array_keys($output);
        sort($keys);
        $expected = ['home_win_probability', 'expected_home_margin', 'expected_total', 'home_cover_probability', 'over_probability',
            'uncertainty', 'model_run_id', 'artifact_id', 'dataset_hash', 'feature_hash'];
        sort($expected);
        if ($keys !== $expected || $output['artifact_id'] !== $admission['artifact']->id
            || $output['model_run_id'] !== $admission['proof']['python_model_run_id']
            || $output['dataset_hash'] !== $admission['proof']['dataset_sha256']
            || ! is_string($output['feature_hash']) || preg_match('/\A[a-f0-9]{64}\z/D', $output['feature_hash']) !== 1) {
            throw new RuntimeException('Inference output differs from its prepared artifact lineage.');
        }
        foreach (['home_win_probability', 'expected_home_margin', 'expected_total', 'home_cover_probability', 'over_probability', 'uncertainty'] as $field) {
            $value = $output[$field];
            if ($value === null && in_array($field, ['home_cover_probability', 'over_probability'], true)) {
                continue;
            }
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)
                || (in_array($field, ['home_win_probability', 'home_cover_probability', 'over_probability', 'uncertainty'], true) && ($value < 0 || $value > 1))) {
                throw new RuntimeException('Inference output contains an invalid finalized numeric field.');
            }
        }
    }

    private function assertOwned($connection, array $proof, array $owned): void
    {
        $this->clocks->assertUnchanged($owned['clock'], $connection);
        MlbTabularSourceFence::assertOwned($connection, $proof);
        MlbArtifactCohortSerializer::assertOwned($connection);
    }

    private function role(ModelArtifact $artifact, bool $lock): array
    {
        $role = $this->row(new ModelArtifact, $artifact->id,
            ['id', 'training_run_id', 'sport', 'model_type', 'status', 'promoted_at'],
            ['promotion_decision' => 262144, 'metrics' => 262144], $lock);
        if ($role->training_run_id !== $artifact->training_run_id || $role->sport !== 'mlb' || $role->model_type !== 'mlb_tabular_bundle') {
            throw new RuntimeException('Inference artifact role owner changed.');
        }
        $current = clone $artifact;
        foreach (['status', 'promoted_at', 'promotion_decision', 'metrics'] as $field) {
            $current->setRawAttributes([...$current->getAttributes(), $field => $role->getRawOriginal($field)], true);
        }

        return ['hash' => $this->hash($role->getRawOriginal()), 'artifact' => $current];
    }

    private function target(PredictionFeatureSnapshot|CanonicalShadowSnapshot $supplied, bool $lock, ?array $expected = null): array
    {
        $game = $this->row(new Game, $supplied->game_id,
            ['id', 'sport_event_id', 'espn_event_id', 'season', 'season_type', 'home_team_id', 'away_team_id', 'game_date', 'game_time', 'status',
                ...MlbGameStart::FIELDS, 'actual_home_pitcher_espn_id', 'actual_away_pitcher_espn_id',
                'probable_home_pitcher_espn_id', 'probable_away_pitcher_espn_id', 'projected_home_pitcher_espn_id', 'projected_away_pitcher_espn_id'],
            ['pitcher_projection_metadata' => 65536], $lock);
        $eventId = $game->sport_event_id === null ? null : (int) $game->sport_event_id;
        if ($expected !== null && ($eventId !== $expected['event_id'] || (int) $game->id !== $expected['game_id'])) {
            throw new RuntimeException('Inference target owner changed; replacement parents are not acquired.');
        }
        if ($game->status !== 'STATUS_SCHEDULED' || MlbGameStart::for($game)?->isFuture() !== true) {
            throw new RuntimeException('Inference output target is no longer a known future scheduled game.');
        }
        if (! $lock && $eventId !== null) {
            $ids = Game::query()->useWritePdo()->select('id')->where('sport_event_id', $eventId)->orderBy('id')->limit(2)->pluck('id')->all();
            if (count($ids) !== 1 || (int) $ids[0] !== (int) $game->id) {
                throw new RuntimeException('Prepared inference event does not have exactly its predetermined Game.');
            }
        }
        $rows = ['game' => $game->getRawOriginal()];
        if ($supplied instanceof CanonicalShadowSnapshot) {
            $lineage = $supplied->lineage;
            $event = $this->row(new SportEvent, $eventId, ['id', 'sport', 'starts_at'], [], $lock);
            $prediction = $this->row(new CanonicalPrediction, $supplied->id,
                ['id', 'public_id', 'sport', 'sport_event_id', 'calculation_run_id', 'revision', 'publication_state', 'phase', 'generated_at', 'published_at', 'model_version', 'feature_version'], [], $lock);
            if ((int) $prediction->sport_event_id !== $eventId || $prediction->calculation_run_id !== $lineage['calculation_run_id']
                || $prediction->publication_state !== 'published' || $prediction->sport !== 'mlb') {
                throw new RuntimeException('Current canonical target revision or owner changed.');
            }
            $run = $this->row(new CalculationRun, $lineage['calculation_run_id'],
                ['id', 'sport_event_id', 'event_input_snapshot_id', 'calculation_release_id', 'status'], [], $lock);
            if ((int) $run->event_input_snapshot_id !== (int) $lineage['event_input_snapshot_id']
                || $run->calculation_release_id !== $lineage['calculation_release_id'] || (int) $run->sport_event_id !== $eventId) {
                throw new RuntimeException('Current canonical input or release owner changed.');
            }
            $input = $this->row(new EventInputSnapshot, $lineage['event_input_snapshot_id'],
                ['id', 'sport', 'sport_event_id', 'phase', 'captured_at', 'cutoff_at', 'latest_source_available_at', 'pregame_safety_status', 'content_hash', 'schema_version'],
                ['inputs' => 2097152, 'metadata' => 262144], $lock);
            $release = $this->row(new CalculationRelease, $lineage['calculation_release_id'], ['id', 'status'], [], $lock);
            $marketIds = PredictionMarket::query()->useWritePdo()->where('prediction_id', $prediction->id)->orderBy('id')->limit(17)
                ->when($lock, fn ($query) => $query->lockForUpdate())->pluck('id');
            if ($marketIds->count() > 16) {
                throw new RuntimeException('Inference target markets exceed the reviewed count.');
            }
            $markets = new Collection;
            foreach ($marketIds as $marketId) {
                $market = $this->row(new PredictionMarket, $marketId, ['id', 'prediction_id', 'market_type', 'selection', 'projected_line', 'probability'], [], $lock);
                if ((int) $market->prediction_id !== (int) $prediction->id) {
                    throw new RuntimeException('Inference target market changed owner.');
                }
                $markets->push($market);
            }
            $event->setRelation('mlbGame', $game);
            $run->setRelation('inputSnapshot', $input)->setRelation('release', $release);
            $prediction->setRelation('sportEvent', $event)->setRelation('calculationRun', $run)->setRelation('markets', $markets);
            $snapshot = app(CanonicalShadowInputs::class)->fromOwnedBoundedMlbPrediction($prediction, $game);
            if ($snapshot === null) {
                throw new RuntimeException('Current canonical target source is unavailable.');
            }
            foreach (['event' => $event, 'prediction' => $prediction, 'run' => $run, 'input' => $input, 'release' => $release] as $name => $model) {
                $rows[$name] = $model->getRawOriginal();
            }
            $rows['markets'] = $markets->map->getRawOriginal()->all();
        } else {
            $snapshot = $this->row(new PredictionFeatureSnapshot, $supplied->id,
                ['id', 'sport', 'game_id', 'prediction_table', 'prediction_id', 'canonical_prediction_id', 'model_run_id', 'snapshot_run_id', 'model_version', 'feature_version', 'blend_version',
                    'feature_hash', 'generated_at', 'game_start_at', 'features_available_at', 'pregame_safe', 'availability_status'],
                ['features' => 262144, 'outputs' => 262144, 'market_context' => 262144, 'model_metadata' => 262144, 'source_timestamps' => 262144, 'lineage_metadata' => 262144], $lock);
            if ((int) $snapshot->game_id !== (int) $game->id || $snapshot->sport !== 'mlb' || $snapshot->prediction_table !== 'mlb_predictions'
                || $snapshot->model_run_id !== $supplied->model_run_id || $snapshot->prediction_id !== $supplied->prediction_id
                || ! $snapshot->pregame_safe || $snapshot->availability_status !== 'observed_pregame'
                || $snapshot->generated_at === null || $snapshot->game_start_at === null || $snapshot->features_available_at === null
                || ! $snapshot->generated_at->lt($snapshot->game_start_at) || $snapshot->features_available_at->gt($snapshot->game_start_at)) {
                throw new RuntimeException('Legacy inference capture changed ownership or source qualification.');
            }
            // The same Game lock serializes ordinary current-capture writers. A newer
            // eligible receipt is a new target revision, even when the old row is intact.
            $latest = PredictionFeatureSnapshot::query()->withoutEagerLoads()->useWritePdo()->select('id')
                ->where('sport', 'mlb')->where('prediction_table', 'mlb_predictions')->where('game_id', $game->id)
                ->where('pregame_safe', true)->where('availability_status', 'observed_pregame')
                ->whereNotNull('game_start_at')->whereNotNull('features_available_at')->where('game_start_at', '>', now())
                ->whereColumn('generated_at', '<', 'game_start_at')->whereColumn('features_available_at', '<=', 'game_start_at')
                ->whereHas('modelRun', fn ($query) => $query->where('sport', 'mlb'))
                ->orderByDesc('generated_at')->orderByDesc('id')->when($lock, fn ($query) => $query->lockForUpdate())->first();
            if ($latest === null || (int) $latest->id !== (int) $snapshot->id) {
                throw new RuntimeException('Current legacy capture revision changed before output storage.');
            }
            $rows['snapshot'] = $snapshot->getRawOriginal();
            $bodies = array_intersect_key($snapshot->getRawOriginal(), array_fill_keys(['features', 'outputs', 'market_context', 'model_metadata', 'source_timestamps', 'lineage_metadata'], true));
            $metadata = app(MlbTabularSnapshotReader::class)->resolveInferenceTargetMetadata($bodies, $lock);
            $snapshot->setRawAttributes([...$snapshot->getAttributes(), 'model_metadata' => $metadata], true);
            $snapshot->setRelation('metadataPayload', null);
        }
        foreach (['home', 'away'] as $side) {
            $captured = trim((string) (data_get($snapshot->features, $side.'_probable_pitcher_espn_id')
                ?? data_get($snapshot->model_metadata, 'pitcher_inputs.'.$side.'_probable_pitcher_espn_id') ?? ''));
            if ((bool) config('mlb_ml.shadow.require_probable_pitchers', true)
                && ($captured === '' || $captured !== trim((string) $game->resolvedStartingPitcherEspnId($side)))) {
                throw new RuntimeException('Current inference target starter differs from its captured input.');
            }
        }

        return ['game_id' => (int) $game->id, 'event_id' => $eventId, 'hash' => $this->hash($rows), 'snapshot' => $snapshot];
    }

    /** CASE bounds apply in the same query as each body; no unrestricted eager relations. */
    private function row(Model $model, mixed $id, array $scalars, array $bodies, bool $lock): Model
    {
        $connection = (new Game)->getConnection();
        if ($model->getConnection() !== $connection || ! in_array($connection->getDriverName(), ['mysql', 'sqlite'], true) || $id === null) {
            throw new RuntimeException('Inference target has an unsupported owner or connection.');
        }
        $select = $invalid = [];
        $grammar = $connection->getQueryGrammar();
        foreach ([...array_fill_keys($scalars, 4096), ...$bodies] as $field => $cap) {
            $column = $grammar->wrap($field);
            $bytes = $connection->getDriverName() === 'mysql' ? 'OCTET_LENGTH('.$column.')' : 'length(CAST('.$column.' AS BLOB))';
            $select[] = 'CASE WHEN COALESCE('.$bytes.',0) <= '.$cap.' THEN '.$column.' ELSE NULL END AS '.$column;
            $invalid[] = 'COALESCE('.$bytes.',0) > '.$cap;
        }
        $row = $model->newModelQuery()->withoutEagerLoads()->useWritePdo()->whereKey($id)
            ->selectRaw(implode(', ', $select).', CASE WHEN ('.implode(' OR ', $invalid).') THEN 1 ELSE 0 END AS _oversized')
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($row === null || (int) $row->getAttribute('_oversized') !== 0) {
            throw new RuntimeException('Inference target row is missing or exceeds its allocation budget.');
        }
        foreach ($bodies as $field => $cap) {
            $raw = $row->getRawOriginal($field);
            if ($raw !== null) {
                if (in_array($field, ['features', 'model_metadata'], true) && str_contains($raw, '"_picksports_feature_archive"')) {
                    throw new RuntimeException('File-backed inference targets require a separate pre-staged descriptor protocol.');
                }
                MlbTabularJsonBudget::inspect($raw, $cap, 50000, 24);
            }
        }
        $row->offsetUnset('_oversized');
        $row->syncOriginal();

        return $row;
    }

    private function payload(PredictionFeatureSnapshot|CanonicalShadowSnapshot $snapshot): string
    {
        return $this->hash(['features' => $snapshot->features, 'outputs' => $snapshot->outputs, 'market' => $snapshot->market_context,
            'metadata' => $snapshot->model_metadata, 'feature_hash' => $snapshot->feature_hash]);
    }

    private function hash(array $value): string
    {
        return hash('sha256', MlbTabularJsonBudget::encode($value, 4194304, 150000, 28));
    }
}
