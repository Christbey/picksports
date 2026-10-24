<?php

namespace App\Services\NFL\Simulation;

use App\Models\NFL\Game;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Services\NFL\Research\ResearchPipeline;
use App\Services\Predictions\SnapshotProvenanceResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class GameSimulationService
{
    public const POLICY = 'nfl-game-simulation-shadow-v1';

    public function __construct(private SimulationHistory $history, private ScoreDistributionSimulator $simulator) {}

    public function preview(Game $game, string $basis = 'published', ?int $iterations = null, ?int $seed = null, bool $provisional = false): array
    {
        $asOf = now();
        $kickoff = app(SnapshotProvenanceResolver::class)->gameStartAt($game)?->setTimezone(config('app.timezone'));
        if (! in_array($basis, ['published', 'pre_market'], true) || ! in_array((string) $game->season_type, ['2', 'regular'], true)
            || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true) || ! $kickoff?->isFuture()) {
            throw new RuntimeException('Simulation requires an upcoming regular-season game and a supported forecast basis.');
        }
        $source = $this->history->source($game, $asOf);
        if (! $source) {
            return ['status' => 'hold', 'reason' => 'observed_pregame_forecast_missing_or_stale', 'bet_eligible' => false];
        }
        if (! ($means = $this->history->means($source, $basis))) {
            return ['status' => 'hold', 'reason' => 'forecast_basis_unavailable', 'bet_eligible' => false];
        }
        foreach (['home', 'away'] as $side) {
            $projected = data_get($source->model_metadata, 'qb_form.'.$side.'.qb_id');
            $current = $game->getAttribute($side.'_qb_id');
            if ($projected && $current && (int) $projected !== (int) $current) {
                return ['status' => 'hold', 'reason' => 'quarterback_identity_changed', 'side' => $side, 'bet_eligible' => false];
            }
        }
        $fit = $this->history->fit($game, $source, $basis, $source->generated_at);
        $minimum = max(2, (int) config('nfl_game_simulation.minimum_history', 200));
        $fitted = count($fit['pairs']) >= $minimum;
        if (! $fitted && ! $provisional) {
            return ['status' => 'hold', 'reason' => 'insufficient_same_model_history', 'history_samples' => count($fit['pairs']), 'required_samples' => $minimum, 'bet_eligible' => false];
        }
        $pipeline = app(ResearchPipeline::class);
        $markets = ['spreads' => $pipeline->quotes($game), 'totals' => $pipeline->additionalMarketQuotes($game, 'totals')];
        $iterations ??= (int) config('nfl_game_simulation.iterations', 10000);
        $seed ??= (int) config('nfl_game_simulation.seed', 20261024);
        $assumptions = [(float) config('nfl_game_simulation.provisional_home_sd', 10),
            (float) config('nfl_game_simulation.provisional_away_sd', 10), (float) config('nfl_game_simulation.provisional_correlation', 0.15)];
        $features = ['game_context_hash' => self::gameContextHash($game), 'source_snapshot_id' => $source->id, 'source_model_version' => $source->model_version, 'game_identity' => self::gameIdentity($game),
            'means' => $means, 'basis' => $basis, 'history' => $fit, 'iterations' => $iterations, 'seed' => $seed,
            'provisional' => ! $fitted ? $assumptions : null];
        $outputs = $this->simulator->run($means['margin'], $means['total'], $fitted ? $fit['pairs'] : [], $markets, $iterations, $seed, $fitted ? null : $assumptions);

        return ['status' => 'shadow', 'game_id' => $game->id, 'source_snapshot_id' => $source->id,
            'source_generated_at' => $source->generated_at->toIso8601String(), 'kickoff' => $kickoff->toIso8601String(),
            'features_available_at' => $asOf->toIso8601String(), 'fitted' => $fitted, 'features' => $features, 'outputs' => $outputs,
            'markets' => $markets, 'market_status' => array_map(fn ($quotes) => $quotes === [] ? 'unavailable_or_stale' : 'fresh_stored_quotes', $markets), 'market_directly_blended' => $basis === 'published' ? data_get($source->model_metadata, 'market_blend.applied') : false,
            'independence_note' => 'Pre-market removes the recorded direct blend only; indirect market effects have not been ruled out.',
            'hash' => self::fingerprint($features, $outputs, $markets)];
    }

    public function capture(Game $game, array $run): PredictionFeatureSnapshot
    {
        if (! config('nfl_game_simulation.capture_enabled', false) || ($run['status'] ?? null) !== 'shadow' || ! ($run['fitted'] ?? false)) {
            throw new RuntimeException('Capture is disabled or the simulation is not fitted. Provisional previews cannot be persisted.');
        }
        if (! hash_equals($run['hash'], self::fingerprint($run['features'], $run['outputs'], $run['markets']))) {
            throw new RuntimeException('Simulation payload integrity check failed.');
        }

        return DB::transaction(function () use ($game, $run) {
            $current = Game::lockForUpdate()->findOrFail($game->id);
            $kickoff = app(SnapshotProvenanceResolver::class)->gameStartAt($current)?->setTimezone(config('app.timezone'));
            if ((int) $run['game_id'] !== (int) $current->id || ! $kickoff?->isFuture()
                || ! in_array($current->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)
                || self::gameContextHash($current) !== $run['features']['game_context_hash']) {
                throw new RuntimeException('Game changed or kickoff passed; generate a new preview.');
            }
            $identity = Uuid::uuid5(Uuid::NAMESPACE_URL, self::POLICY.':'.ScoreDistributionSimulator::VERSION.':'.$game->id.':'.$run['features']['basis'])->toString();
            $existing = PredictionFeatureSnapshot::where('snapshot_run_id', $identity)->first();
            if ($existing) {
                if (! hash_equals($existing->feature_hash, $run['hash'])) {
                    throw new RuntimeException('A different first observation already exists; it will not be replaced.');
                }

                return $existing;
            }
            $available = Carbon::parse($run['features_available_at']);
            if ($available->isFuture() || ! $available->lt($kickoff) || $run['outputs']['bet_eligible'] !== false
                || $run['features']['provisional'] !== null || count($run['features']['history']['pairs']) < max(2, (int) config('nfl_game_simulation.minimum_history', 200))) {
                throw new RuntimeException('Simulation capture lacks fitted pregame inputs.');
            }
            $source = PredictionFeatureSnapshot::lockForUpdate()->findOrFail($run['source_snapshot_id']);
            if ((int) $source->game_id !== (int) $game->id || ! $source->pregame_safe || $source->availability_status !== 'observed_pregame'
                || (int) $source->id !== (int) $run['features']['source_snapshot_id'] || $source->model_version !== $run['features']['source_model_version']
                || ! $source->generated_at?->lte($available) || ! $source->features_available_at?->lte($available)
                || ! $source->generated_at?->lt($kickoff) || ! $source->features_available_at?->lt($kickoff)
                || $this->history->means($source, $run['features']['basis']) !== $run['features']['means']) {
                throw new RuntimeException('Source forecast changed or lacks pregame provenance.');
            }

            return PredictionFeatureSnapshot::create(['snapshot_run_id' => $identity, 'sport' => 'nfl', 'prediction_table' => 'prediction_feature_snapshots',
                'prediction_id' => $source->id, 'game_id' => $game->id, 'model_version' => ScoreDistributionSimulator::VERSION,
                'feature_version' => 'paired-score-residuals-v1', 'blend_version' => self::POLICY, 'features' => $run['features'],
                'outputs' => $run['outputs'], 'market_context' => $run['markets'], 'feature_hash' => $run['hash'],
                'model_metadata' => ['php_runtime' => PHP_VERSION, 'rng' => 'Random\\Engine\\Mt19937', 'calibrated' => false, 'bet_eligible' => false, 'source_snapshot_id' => $source->id,
                    'market_directly_blended' => $run['market_directly_blended'], 'independence_note' => $run['independence_note']],
                'generated_at' => now(), 'game_start_at' => $kickoff, 'features_available_at' => $run['features_available_at'],
                'pregame_safe' => true, 'availability_status' => 'observed_pregame',
                'source_timestamps' => ['source_forecast_generated_at' => $run['source_generated_at'], 'captured_at' => now()->toIso8601String()]]);
        });
    }

    public function grade(PredictionFeatureSnapshot $snapshot): ?PredictionEvaluation
    {
        if ($snapshot->blend_version !== self::POLICY || ! hash_equals($snapshot->feature_hash, self::fingerprint($snapshot->features, $snapshot->outputs, $snapshot->market_context))) {
            throw new RuntimeException('Invalid simulation snapshot.');
        }
        $game = Game::findOrFail($snapshot->game_id);
        if (($snapshot->features['game_identity'] ?? null) !== self::gameIdentity($game)) {
            return null;
        }
        $mature = now()->subHours((int) config('nfl_game_simulation.result_maturity_hours', 24));
        if ($game->status !== 'STATUS_FINAL' || $game->home_score === null || $game->away_score === null || $game->home_score < 0 || $game->away_score < 0 || $game->home_score > 200 || $game->away_score > 200
            || ! $game->result_updated_at?->lte($mature)) {
            return null;
        }
        $m = (int) $game->home_score - (int) $game->away_score;
        $t = (int) $game->home_score + (int) $game->away_score;
        $o = $snapshot->outputs;
        $winner = $m > 0 ? 'home' : ($m < 0 ? 'away' : 'tie');
        $brier = 0;
        foreach ($o['outright'] as $side => $p) {
            $brier += ($p - (int) ($winner === $side)) ** 2;
        }
        $errors = ['margin_absolute_error' => abs($o['distributions']['margin']['mean'] - $m),
            'total_absolute_error' => abs($o['distributions']['total']['mean'] - $t), 'outright_brier' => $brier];
        foreach (['margin' => $m, 'total' => $t] as $key => $actual) {
            $d = $o['distributions'][$key];
            $errors[$key.'_interval_90_covered'] = $actual >= $d['p5'] && $actual <= $d['p95'];
            $errors[$key.'_baseline_absolute_error'] = abs($snapshot->features['means'][$key] - $actual);
        }
        $markets = [];
        foreach ($o['markets'] as $market) {
            $delta = ScoreDistributionSimulator::settlementDelta($market['market'], $market['quote'], $m, $t);
            $result = $delta > 0 ? 'win' : ($delta < 0 ? 'loss' : 'push');
            $market['actual_outcome'] = $result;
            $market['brier'] = array_sum(array_map(fn ($side) => ($market['frequencies'][$side] - (int) ($result === $side)) ** 2, ['win', 'loss', 'push']));
            $market['line_absolute_error'] = $market['market'] === 'totals' ? abs($t - $market['quote']['line'])
                : abs($m + ($market['quote']['side'] === 'home' ? $market['quote']['line'] : -$market['quote']['line']));
            $markets[] = $market;
        }

        return PredictionEvaluation::firstOrCreate(['prediction_table' => 'prediction_feature_snapshots', 'prediction_id' => $snapshot->id,
            'model_version' => $snapshot->model_version, 'feature_version' => $snapshot->feature_version, 'blend_version' => self::POLICY],
            ['sport' => 'nfl', 'scoring_version' => 'nfl-score-simulation-scoring-v1', 'game_id' => $game->id, 'actuals' => ['home_score' => $game->home_score, 'away_score' => $game->away_score,
                'margin' => $m, 'total' => $t, 'result_updated_at' => $game->result_updated_at->toIso8601String()],
                'errors' => $errors, 'market_comparison' => ['markets' => $markets, 'bet_eligible' => false], 'evaluated_at' => now()]);
    }

    private static function gameIdentity(Game $game): array
    {
        return ['home_team_id' => (int) $game->home_team_id, 'away_team_id' => (int) $game->away_team_id,
            'season' => (int) $game->season, 'season_type' => (string) $game->season_type, 'espn_event_id' => (string) $game->espn_event_id];
    }

    private static function gameContextHash(Game $game): string
    {
        return self::fingerprint(['status' => $game->status, 'game_id' => (int) $game->id,
            'home_team_id' => (int) $game->home_team_id, 'away_team_id' => (int) $game->away_team_id,
            'season_type' => (string) $game->season_type, 'home_qb_id' => $game->home_qb_id, 'away_qb_id' => $game->away_qb_id, 'game_date' => $game->game_date?->toDateString(),
            'game_time' => $game->game_time], [], $game->odds_data ?? []);
    }

    public static function fingerprint(array $features, array $outputs, array $market): string
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return is_float($value) && $value == 0 ? 0 : $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };

        return hash('sha256', json_encode($canonical([$features, $outputs, $market]), JSON_THROW_ON_ERROR));
    }
}
