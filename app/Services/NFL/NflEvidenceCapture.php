<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Models\NflSignalObservation;
use App\Models\PredictionFeatureSnapshot;
use App\Services\NFL\Matchups\NflMatchupSignalCatalog;
use App\Services\NFL\Matchups\NflMatchupSignalService;
use App\Services\Predictions\ModelRunRecorder;
use App\Services\Predictions\SnapshotProvenanceResolver;
use App\Support\NflModelVersion;
use App\Support\NflReasonCodeCatalog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NflEvidenceCapture
{
    private array $atomicUniverse = [];

    public const POLICY = 'nfl-evidence-v1';

    public function capture(int $season, int $limit = 16): array
    {
        $result = ['created' => 0, 'existing' => 0, 'skipped' => [], 'observations_created' => 0];
        $games = Game::with(['sportEvent', 'homeTeam', 'awayTeam'])->where('season', $season)
            ->whereIn('season_type', ['2', 'regular'])->whereNotIn('status', ['STATUS_FINAL', 'STATUS_CANCELED'])
            ->whereBetween('game_date', [now()->subDay()->toDateString(), now()->addDays(2)->toDateString()])->orderBy('game_date')->orderBy('game_time')->get();
        foreach ($games as $game) {
            if ($result['created'] >= max(1, min(16, $limit))) {
                break;
            }
            $kickoff = app(SnapshotProvenanceResolver::class)->gameStartAt($game);
            if ($kickoff === null || ! $kickoff->isFuture() || $kickoff->gt(now()->addDay())) {
                continue;
            }
            $phase = $kickoff->lte(now()->addMinutes(90)) ? 'late' : 'early';
            $lock = Cache::lock('nfl:evidence:'.$game->id.':'.$phase, 180);
            if (! $lock->get()) {
                $result['skipped'][$game->id] = 'capture_locked';

                continue;
            }
            try {
                // At most two compact captures per game; no repeated full catalog per prediction run.
                $existing = PredictionFeatureSnapshot::where('sport', 'nfl')->where('game_id', $game->id)
                    ->where('blend_version', self::POLICY)->where('features->evidence->phase', $phase)->first();
                if ($existing) {
                    app(NflSignalObservationMaterializer::class)->materialize($existing);
                    app(NflEvidenceTrialService::class)->recordShadows($existing);
                    $result['existing']++;

                    continue;
                }
                $source = PredictionFeatureSnapshot::where('sport', 'nfl')->where('game_id', $game->id)
                    ->where('prediction_table', 'nfl_predictions')->where('model_version', NflModelVersion::current())
                    ->where('blend_version', '!=', self::POLICY)->where('pregame_safe', true)
                    ->where('availability_status', 'observed_pregame')->where('generated_at', '<=', now())
                    ->where('generated_at', '>=', now()->subHours(2))->whereColumn('features_available_at', '<=', 'generated_at')
                    ->orderByDesc('generated_at')->orderByDesc('id')->first();
                if (! $source || ! $source->game_start_at?->eq($kickoff)) {
                    $result['skipped'][$game->id] = 'missing_fresh_verified_forecast';

                    continue;
                }
                $run = app(ModelRunRecorder::class)->forPrediction('nfl', $source->model_version, self::POLICY, self::POLICY, ['run_type' => 'evidence_capture']);
                if ($source->modelRun?->config_hash !== $run->config_hash) {
                    $result['skipped'][$game->id] = 'source_configuration_changed';

                    continue;
                }
                $started = now()->toImmutable();
                $catalog = app(NflMatchupSignalService::class)->build($game, 'season_to_date');
                $conditions = [...$this->compact($catalog, $game), ...$this->atomicConditions($source)];
                if (! $kickoff->isFuture()) {
                    $result['skipped'][$game->id] = 'kickoff_during_capture';

                    continue;
                }
                $context = ['cohort' => app(NflEvidenceLeaderboard::class)->cohort($game),
                    'home_qb' => data_get($source->model_metadata, 'qb_form.home.qb_id'),
                    'away_qb' => data_get($source->model_metadata, 'qb_form.away.qb_id'),
                    'qb_status' => [data_get($source->model_metadata, 'qb_form.home.identity_status', 'unknown'), data_get($source->model_metadata, 'qb_form.away.identity_status', 'unknown')],
                    'home_rest' => $game->home_rest, 'away_rest' => $game->away_rest,
                    'meeting' => 1 + Game::where('season', $season)->whereIn('season_type', ['2', 'regular'])->where('status', 'STATUS_FINAL')
                        ->whereDate('game_date', '<', $game->game_date)->whereIn('home_team_id', [$game->home_team_id, $game->away_team_id])
                        ->whereIn('away_team_id', [$game->home_team_id, $game->away_team_id])->count()];
                $features = ['evidence' => ['policy' => self::POLICY, 'version' => $catalog['version'], 'phase' => $phase,
                    'source_snapshot_id' => $source->id, 'source_feature_hash' => $source->feature_hash, 'source_config_hash' => $source->modelRun->config_hash,
                    'collection_started_at' => $started->toIso8601String(), 'baseline' => $catalog['baseline'],
                    'context' => $context, 'conditions' => $conditions]];
                $capturedAt = now();
                $snapshot = DB::transaction(function () use ($source, $game, $kickoff, $features, $run, $capturedAt) {
                    $snapshot = PredictionFeatureSnapshot::create(['sport' => 'nfl', 'prediction_table' => 'nfl_predictions',
                        'prediction_id' => $source->prediction_id, 'game_id' => $game->id, 'snapshot_run_id' => (string) Str::uuid(),
                        'model_run_id' => $run->id, 'model_version' => $source->model_version, 'feature_version' => self::POLICY, 'blend_version' => self::POLICY,
                        'features' => $features, 'outputs' => $source->outputs, 'market_context' => $source->market_context,
                        'model_metadata' => ['evidence_only' => true, 'source_snapshot_id' => $source->id],
                        'feature_hash' => self::fingerprint($features, $source->outputs, $source->market_context),
                        'generated_at' => $capturedAt, 'features_available_at' => $capturedAt, 'game_start_at' => $kickoff->setTimezone(config('app.timezone')),
                        'pregame_safe' => true, 'availability_status' => 'observed_pregame',
                        'source_timestamps' => ['forecast_generated_at' => $source->generated_at->toIso8601String(), 'quote_observed_at' => $source->market_context['odds_updated_at'] ?? null]]);
                    $created = app(NflSignalObservationMaterializer::class)->materialize($snapshot)['created'];

                    return [$snapshot, $created];
                });
                [$snapshot, $created] = $snapshot;
                $result['observations_created'] += $created;
                app(NflEvidenceTrialService::class)->recordShadows($snapshot);
                $result['created']++;
            } finally {
                $lock->release();
            }
        }

        return $result;
    }

    public function compact(array $catalog, Game $game): array
    {
        $conditions = [];
        $rules = app(NflMatchupSignalCatalog::class)->rules();
        foreach ($catalog['signals'] as $signal) {
            $side = (int) $signal['offense_team_id'] === (int) $game->home_team_id ? 'home' : 'away';
            $e = $signal['evidence'] ?? [];
            $sample = fn ($v) => [...array_intersect_key((array) $v, array_flip(['metric', 'value', 'rank', 'rank_end', 'games', 'plays', 'eligible', 'league_teams', 'through_date', 'source_updated_at', 'observed_at'])), 'personnel_refs' => array_intersect_key((array) ($v['personnel'] ?? []), array_flip(['player_id', 'player_name', 'injury_snapshot_id', 'depth_chart_snapshot_id', 'depth_chart_link_id', 'as_of']))];
            $conditions['catalog_'.$signal['id'].'_'.$side] = [
                'id' => $signal['id'], 'side' => $side, 'label' => $signal['label'], 'state' => $signal['status'],
                'definition' => ['version' => $catalog['version'], 'id' => $signal['id'], 'side' => $side,
                    'rule' => $rules[$signal['id']] ?? null, 'metric' => $e['metric'] ?? null],
                'offense' => $sample($e['offense'] ?? []), 'defense' => $sample($e['defense'] ?? []),
                'reason' => $signal['reason'] ?? null, 'source' => $e['source'] ?? null];
        }
        foreach ($catalog['catalog'] as $entry) {
            foreach (['home', 'away'] as $side) {
                $key = 'catalog_'.$entry['id'].'_'.$side;
                $conditions[$key] ??= ['id' => $entry['id'], 'side' => $side, 'label' => $entry['label'], 'state' => 'unsupported',
                    'definition' => ['version' => $catalog['version'], 'id' => $entry['id'], 'side' => $side, 'support' => $entry['support'] ?? null],
                    'reason' => 'not_implemented_in_catalog'];
            }
        }
        // Do not let unsupported NaN values silently become numeric evidence.
        $normalize = function ($v) use (&$normalize) {
            if (is_float($v) && ! is_finite($v)) {
                return null;
            }

            return is_array($v) ? array_map($normalize, $v) : $v;
        };

        return $normalize($conditions);
    }

    private function atomicConditions(PredictionFeatureSnapshot $source): array
    {
        $analysis = (array) data_get($source->model_metadata, 'analysis_layer', []);
        $definitions = [
            'reason_code' => ['reason_codes', false], 'risk_flag' => ['risk_flags', false],
            'matched_rule' => ['bet_rule_evaluation.matched_rules', true],
            'pass_rule' => ['bet_rule_evaluation.pass_rules', false],
            'validated_combo' => ['validated_signals', true],
        ];
        $present = [];
        $known = [];
        foreach ($definitions as $type => [$path, $objects]) {
            $value = data_get($analysis, $path);
            $known[$type] = is_array($value);
            $present[$type] = $objects ? collect((array) $value)->pluck('name')->filter()->values()->all() : (array) $value;
        }
        // Candidate membership comes from earlier observed definitions, never their outcomes or hit rates.
        $universe = $this->atomicUniverse[$source->model_version] ??= NflSignalObservation::where('model_version', $source->model_version)
            ->whereIn('signal_type', array_keys($definitions))->where('pregame_safe', true)->where('availability_status', 'observed_pregame')
            ->where('observed_at', '<=', now())->select('signal_type', 'signal_key')->distinct()->get()
            ->groupBy('signal_type')->map(fn ($rows) => $rows->pluck('signal_key')->all())->all();
        $conditions = [];
        foreach ($definitions as $type => [$path, $objects]) {
            foreach (array_unique([...($universe[$type] ?? []), ...$present[$type]]) as $token) {
                if (! is_string($token) || $token === '') {
                    continue;
                }
                $metadata = app(NflReasonCodeCatalog::class)->metadata($token);
                $key = 'source_'.$type.'_'.$token;
                if (strlen($key) > 150) {
                    $key = substr($key, 0, 130).'_'.substr(hash('sha256', $key), 0, 12);
                }
                $conditions[$key] = ['label' => str_replace('_', ' ', $type).': '.($metadata['label'] ?? $token),
                    'state' => ! $known[$type] ? 'insufficient_data' : (in_array($token, $present[$type], true) ? 'matched' : 'not_matched'),
                    'definition' => ['type' => $type, 'token' => $token, 'metadata' => $metadata,
                        'definition_version' => hash_file('sha256', (new \ReflectionClass(NflReasonCodeCatalog::class))->getFileName())],
                    'source' => 'frozen_source_prediction.analysis_layer.'.$path];
            }
        }

        return $conditions;
    }

    public static function fingerprint(array $features, array $outputs, ?array $market): string
    {
        $normalize = function ($value) use (&$normalize) {
            if (is_int($value) || is_float($value)) {
                if (! is_finite((float) $value)) {
                    throw new \InvalidArgumentException('Non-finite evidence value.');
                }

                return number_format((float) $value, 8, '.', '');
            }

            return is_array($value) ? Arr::sortRecursive(array_map($normalize, $value)) : $value;
        };

        return hash('sha256', json_encode($normalize([$features, $outputs, $market]), JSON_THROW_ON_ERROR));
    }
}
