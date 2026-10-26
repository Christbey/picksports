<?php

namespace App\Services\NFL;

use App\Actions\GradePlayerProps;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerProp;
use App\Models\NFL\PlayerStat;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Services\BettingRecommendations\NflPropAvailabilityContext;
use App\Services\BettingRecommendations\NflPropCountDistribution;
use App\Services\BettingRecommendations\NflPropMatchupAdjustment;
use App\Services\BettingRecommendations\NflPropOpportunityProjection;
use App\Services\BettingRecommendations\NflPropRoleHistory;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Append-only pregame probabilities; independent of recommendation publication. */
class NflPropProbabilityExperiment extends PlayerPropAnalyzer
{
    public const POLICY = 'nfl-prop-probability-v1';

    private array $captured = [];

    protected function persistPredictionSnapshot(Model $prop, array $analysis, int $dataQualityScore, int $matchQualityScore, float $contextFactor): void
    {
        $this->captured[$prop->id] = ['analysis' => $analysis, 'attributes' => $prop->getAttributes()];
    }

    private ?NflPropMatchupAdjustment $experimentMatchups = null;

    private function experimentModelVersion(): string
    {
        return config('nfl.props.opportunity_projection_enabled', false)
            ? 'nfl-player-prop-v5-opportunity-matchup-shadow' : 'nfl-player-prop-v5-matchup-shadow';
    }

    protected function buildContextAdjustments(Model $game, int $playerId, int $playerTeamId, int $opponentTeamId, string $market, array $sportConfig): array
    {
        $context = parent::buildContextAdjustments($game, $playerId, $playerTeamId, $opponentTeamId, $market, $sportConfig);
        if (! $game instanceof Game || ! ($player = Player::find($playerId))) {
            return $context;
        }
        $history = $this->finalizedPlayerStatsQuery($playerId, PlayerStat::class)->with('game')->get();
        if (in_array($market, ['player_receptions', 'player_reception_yds'], true)) {
            $expectedQb = config('nfl.props.opportunity_projection_enabled', false)
                ? ($game->home_team_id === $playerTeamId ? $game->home_qb_name : $game->away_qb_name) : null;
            $history = app(NflPropRoleHistory::class)->select($history, $player, $expectedQb)['history'];
        }
        $matchup = ($this->experimentMatchups ??= app(NflPropMatchupAdjustment::class))->resolve($game, $player, $market, $history);
        if ($matchup['field'] !== null) {
            $context['opponent_factor'] = $matchup['factor'];
            $context['opponent_evidence'] = $matchup;
            $context['combined_factor'] = round(max(0.85, min(1.15, $context['pace_factor'] * $matchup['factor'] * $context['usage_factor'])), 3);
        }

        return $context;
    }

    public function capture(int $season, ?int $gameId = null): array
    {
        $created = 0;
        $this->experimentMatchups = null;
        $code = [];
        foreach ([PlayerPropAnalyzer::class, NflPropMatchupAdjustment::class,
            NflPropCountDistribution::class, NflPropRoleHistory::class,
            NflPropOpportunityProjection::class, NflPropAvailabilityContext::class, self::class] as $class) {
            $code[$class] = hash_file('sha256', (new \ReflectionClass($class))->getFileName());
        }
        $version = hash('sha256', json_encode([$code, $this->experimentModelVersion()], JSON_THROW_ON_ERROR));
        Game::query()->where('season', $season)->whereIn('season_type', ['2', 'regular'])->where('status', 'STATUS_SCHEDULED')
            ->whereBetween('game_date', [now()->subDay()->toDateString(), now()->addDays(2)->toDateString()])
            ->when($gameId !== null, fn ($q) => $q->whereKey($gameId))->each(function ($game) use (&$created, $version, $code) {
                $kickoff = app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);
                if (! $kickoff || ! $kickoff->isFuture() || $kickoff->gt(now()->addDay())) {
                    return;
                }
                $this->captured = [];
                $this->previewNflGame($game);
                $props = PlayerProp::with('player')->whereIn('id', array_keys($this->captured))->get();
                foreach ($props as $prop) {
                    $analysis = $this->captured[$prop->id]['analysis'];
                    if ($prop->getAttributes() !== $this->captured[$prop->id]['attributes']) {
                        continue;
                    }
                    $prop->setRelation('game', $game);
                    if (! isset($analysis['confidence_decomposition']['projected_mean'], $analysis['model_over_probability']) || ! $prop->player) {
                        continue;
                    }
                    $created += (int) DB::transaction(function () use ($prop, $analysis, $kickoff, $version, $code) {
                        $current = PlayerProp::query()->lockForUpdate()->find($prop->id);
                        $currentGame = Game::query()->lockForUpdate()->find($prop->game_id);
                        $player = Player::query()->lockForUpdate()->find($prop->player_id);
                        $fields = ['game_id', 'player_id', 'market', 'line', 'over_price', 'under_price', 'bookmaker', 'fetched_at', 'updated_at'];
                        if (! $current || ! $currentGame || ! $player || $currentGame->getAttributes() !== $prop->game->getAttributes()
                            || $player->getAttributes() !== $prop->player->getAttributes()
                            || Arr::only($current->getAttributes(), $fields) !== Arr::only($prop->getAttributes(), $fields)
                            || ! self::nflPregame($currentGame) || $current->graded_at || ! $current->fetched_at
                            || $current->fetched_at->gt(now()) || $current->fetched_at->lt($kickoff->subHours(36))) {
                            return false;
                        }
                        $context = $analysis['confidence_decomposition'];
                        $features = ['projection_context' => $context['projection_context'] ?? [], 'history' => $context['history'] ?? [], 'code_sha256' => $code, 'kickoff_at' => $kickoff->toIso8601String(), 'fingerprint_version' => 'round8-v1'];
                        $outputs = ['mean' => $context['projected_mean'], 'over_probability' => $analysis['model_over_probability'] / 100,
                            'push_probability' => ($context['push_probability'] ?? 0) / 100];
                        $market = ['market' => $current->market, 'line' => (float) $current->line, 'bookmaker' => $current->bookmaker,
                            'over_price' => $current->over_price, 'under_price' => $current->under_price, 'quote_observed_at' => $current->fetched_at->toIso8601String()];
                        $capturedAt = now();
                        $snapshot = PredictionFeatureSnapshot::firstOrCreate(['snapshot_run_id' => Uuid::uuid5(Uuid::NAMESPACE_URL,
                            implode(':', [self::POLICY, $version, $current->game_id, $current->player_id, $current->market]))->toString()], [
                                'sport' => 'nfl', 'prediction_table' => 'nfl_player_props', 'prediction_id' => $current->id,
                                'game_id' => $current->game_id, 'model_version' => $this->experimentModelVersion(), 'feature_version' => $version,
                                'blend_version' => self::POLICY, 'features' => $features, 'outputs' => $outputs, 'market_context' => $market,
                                'model_metadata' => ['player_id' => $current->player_id, 'team_id' => $player->team_id, 'approved' => false],
                                'feature_hash' => self::fingerprint($features, $outputs, $market),
                                'generated_at' => $capturedAt, 'game_start_at' => $kickoff->setTimezone(config('app.timezone')), 'features_available_at' => $capturedAt,
                                'pregame_safe' => true, 'availability_status' => 'observed_pregame',
                                'source_timestamps' => ['quote_observed_at' => $current->fetched_at->toIso8601String(), 'kickoff_at' => $kickoff->toIso8601String()],
                            ]);

                        return $snapshot->wasRecentlyCreated;
                    });
                }
            });

        return ['created' => $created, 'policy' => self::POLICY, 'code_version' => $version, 'production_promotion' => false];
    }

    public function grade(int $season): array
    {
        $graded = $pending = $unsafe = 0;
        $reader = new class extends GradePlayerProps
        {
            public function value(PlayerProp $prop): ?float
            {
                return $this->getNflActualValue($prop);
            }
        };
        PredictionFeatureSnapshot::where('sport', 'nfl')->where('blend_version', self::POLICY)
            ->whereIn('game_id', Game::where('season', $season)->where('status', 'STATUS_FINAL')->select('id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('prediction_evaluations')->where('prediction_table', 'prediction_feature_snapshots')
                ->where('blend_version', self::POLICY)->whereColumn('prediction_id', 'prediction_feature_snapshots.id'))
            ->chunkById(100, function ($snapshots) use (&$graded, &$pending, &$unsafe, $reader) {
                foreach ($snapshots as $snapshot) {
                    if (($snapshot->features['fingerprint_version'] ?? null) !== 'round8-v1' || ! isset($snapshot->features['kickoff_at']) || ! $snapshot->game_start_at
                        || ! $snapshot->game_start_at->eq(CarbonImmutable::parse($snapshot->features['kickoff_at']))
                        || ! $snapshot->pregame_safe || ! $snapshot->generated_at || ! $snapshot->game_start_at
                        || $snapshot->generated_at->gte($snapshot->game_start_at) || $snapshot->game_start_at->isFuture()
                        || ! $snapshot->features_available_at || $snapshot->features_available_at->gt($snapshot->generated_at)
                        || ! hash_equals($snapshot->feature_hash, self::fingerprint($snapshot->features, $snapshot->outputs, $snapshot->market_context))) {
                        $unsafe++;

                        continue;
                    }
                    $stat = PlayerStat::where('game_id', $snapshot->game_id)->where('player_id', $snapshot->model_metadata['player_id'])->first();
                    if (! $stat || (int) $stat->team_id !== (int) $snapshot->model_metadata['team_id']) {
                        $pending++;

                        continue;
                    }
                    $actual = $reader->value(new PlayerProp(['game_id' => $snapshot->game_id, 'player_id' => $stat->player_id, 'market' => $snapshot->market_context['market']]));
                    if ($actual === null) {
                        $pending++;

                        continue;
                    }
                    $evaluation = PredictionEvaluation::firstOrCreate(['prediction_table' => 'prediction_feature_snapshots', 'prediction_id' => $snapshot->id,
                        'model_version' => $snapshot->model_version, 'feature_version' => $snapshot->feature_version, 'blend_version' => self::POLICY], [
                            'sport' => 'nfl', 'game_id' => $snapshot->game_id, 'actuals' => ['value' => $actual, 'stat_id' => $stat->id],
                            'errors' => ['absolute_error' => abs($snapshot->outputs['mean'] - $actual), 'bias' => $snapshot->outputs['mean'] - $actual],
                            'market_comparison' => ['push' => (float) $snapshot->market_context['line'] === $actual, 'approved' => false], 'evaluated_at' => now()]);
                    $graded += (int) $evaluation->wasRecentlyCreated;
                }
            });

        return ['graded' => $graded, 'pending_missing_actual' => $pending, 'quarantined_unsafe' => $unsafe];
    }

    public static function fingerprint(array $features, array $outputs, array $market): string
    {
        $normalize = function ($value) use (&$normalize) {
            if (is_float($value)) {
                return round($value, 8);
            }
            if (is_array($value)) {
                return array_map($normalize, $value);
            }

            return $value;
        };

        return hash('sha256', json_encode(Arr::sortRecursive($normalize(compact('features', 'outputs', 'market'))), JSON_THROW_ON_ERROR));
    }

    public function monitoring(int $season): array
    {
        $groups = [];
        PredictionFeatureSnapshot::where('blend_version', self::POLICY)->whereIn('game_id', Game::where('season', $season)->select('id'))
            ->chunkById(100, function ($snapshots) use (&$groups) {
                $evaluations = PredictionEvaluation::where('prediction_table', 'prediction_feature_snapshots')->where('blend_version', self::POLICY)
                    ->whereIn('prediction_id', $snapshots->pluck('id'))->get()->keyBy('prediction_id');
                foreach ($snapshots as $snapshot) {
                    $key = $snapshot->feature_version.':'.$snapshot->market_context['market'];
                    $groups[$key] ??= ['code_version' => $snapshot->feature_version, 'market' => $snapshot->market_context['market'], 'captured' => 0, 'quarantined_unsafe' => 0, 'graded' => 0, 'pushes' => 0, 'brier_sum' => 0.0, 'log_loss_sum' => 0.0];
                    $g = &$groups[$key];
                    $g['captured']++;
                    if (($snapshot->features['fingerprint_version'] ?? null) !== 'round8-v1' || ! isset($snapshot->features['kickoff_at']) || ! $snapshot->game_start_at
                        || ! $snapshot->game_start_at->eq(CarbonImmutable::parse($snapshot->features['kickoff_at']))
                        || ! hash_equals($snapshot->feature_hash, self::fingerprint($snapshot->features, $snapshot->outputs, $snapshot->market_context))) {
                        $g['quarantined_unsafe']++;
                        unset($g);

                        continue;
                    }
                    $evaluation = $evaluations->get($snapshot->id);
                    if (! $evaluation) {
                        continue;
                    }
                    if ($evaluation->market_comparison['push']) {
                        $g['pushes']++;

                        continue;
                    }
                    $p = max(.000001, min(.999999, $snapshot->outputs['over_probability']));
                    $y = (int) ($evaluation->actuals['value'] > $snapshot->market_context['line']);
                    $g['graded']++;
                    $g['brier_sum'] += ($p - $y) ** 2;
                    $g['log_loss_sum'] -= log($y ? $p : 1 - $p);
                    unset($g);
                }
            });
        foreach ($groups as &$g) {
            $g['brier'] = $g['graded'] ? $g['brier_sum'] / $g['graded'] : null;
            $g['log_loss'] = $g['graded'] ? $g['log_loss_sum'] / $g['graded'] : null;
            $g['pending'] = $g['captured'] - $g['graded'] - $g['pushes'] - $g['quarantined_unsafe'];
            unset($g['brier_sum'], $g['log_loss_sum']);
        }

        return ['groups' => array_values($groups), 'production_promotion' => false];
    }
}
