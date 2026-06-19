<?php

namespace App\Services\MLB;

use App\Models\MLB\Game;
use App\Models\MLB\PlayoffForecast;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Services\Sports\FuturesEdgeService;
use App\Services\Sports\FuturesOddsLookupService;
use App\Services\Sports\SportsDateWindowService;
use App\Support\MLB\MlbMarketSpread;
use App\Support\Odds\AmericanOdds;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class MlbBettingSignalService
{
    public const FILTER_VERSION = 'selective_mlb_bet_filter_v1';

    public function __construct(
        protected FuturesOddsLookupService $futuresOddsLookup,
        protected FuturesEdgeService $futuresEdgeService,
        protected SportsDateWindowService $dateWindows,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function signals(int $season, ?CarbonInterface $asOfDate = null): array
    {
        $asOfDate ??= now();
        $slateDate = $this->targetSlateDate($season, $asOfDate);
        $slatePredictions = $slateDate !== null ? $this->slatePredictionRows($season, $slateDate) : [];
        $candidateBets = $this->candidateRecommendedBets($slatePredictions);

        return [
            'season' => $season,
            'as_of_date' => $asOfDate->toDateString(),
            'slate_date' => $slateDate?->toDateString(),
            'backend_review' => $this->backendReview(),
            'framework' => $this->frameworkSummary(),
            'odds_health' => $this->oddsHealth($slatePredictions),
            'world_series' => $this->worldSeriesSignals($season),
            'moneyline' => $this->moneylineSignals($slatePredictions),
            'run_line' => $this->runLineSignals($slatePredictions),
            'totals' => $this->totalSignals($slatePredictions),
            'ballpark' => $this->ballparkSignals($slatePredictions),
            'live' => $this->liveSignals($season, $asOfDate),
            'bet_filter' => $this->betFilterSummary(),
            'moneyline_readiness' => $this->moneylineReadiness($slatePredictions),
            'recommended_bets' => $this->publicRecommendedBets($candidateBets),
            'shadow_recommended_bets' => $candidateBets,
            'pass_summary' => $this->passSummary($slatePredictions),
            'streaks' => $this->streakSignals($season, $asOfDate),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function backendReview(): array
    {
        return [
            'model' => 'mlb_elo_pitcher_blend',
            'strengths' => [
                'probable_pitcher_or_depth_chart_starter',
                'pitcher_elo_and_team_elo_blend',
                'season_progress_dynamic_weights',
                'bullpen_fatigue_and_bullpen_quality_context',
                'lineup_handedness_context',
                'starter_form_context',
                'historical_prior_context',
                'ballpark_run_home_run_and_win_context',
                'injury_and_probable_pitcher_availability_adjustments',
                'market_spread_and_total_capture',
            ],
            'watch_items' => [
                'lineups_are_not_yet_projected_batter_by_batter',
                'weather_is_park_proxy_only_until_weather_feed_is_added',
                'run_line_edges_use_vegas_spread_normalized_to_home_margin',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function frameworkSummary(): array
    {
        return [
            'version' => 'sport_signal_framework_v1',
            'shared_with_nfl' => [
                'model_signal_generation',
                'market_edge_detection',
                'bet_classification',
                'trust_or_score',
                'reason_codes',
                'risk_flags',
                'pass_classification',
                'odds_health',
                'result_feedback_loop',
            ],
            'mlb_specific_deviations' => [
                'probable_pitcher_and_depth_chart_starter_context',
                'bullpen_fatigue_and_quality',
                'ballpark_run_environment_for_homeruns_runs_and_win_context',
                'moneyline_price_required_before_recommending_bets',
                'run_line_and_total_promotion_disabled_until_backtest_validates_them',
            ],
        ];
    }

    /**
     * @param  array<int,Prediction>  $predictions
     * @return array<string,mixed>
     */
    protected function oddsHealth(array $predictions): array
    {
        $total = count($predictions);
        $h2h = 0;
        $spreads = 0;
        $totals = 0;
        $stale = 0;
        $missing = [];

        foreach ($predictions as $prediction) {
            $game = $prediction->game;
            $oddsData = $game?->odds_data;
            $hasH2h = $this->extractMarket($oddsData, 'h2h') !== null;
            $hasSpreads = $this->extractMarket($oddsData, 'spreads') !== null || $prediction->vegas_spread !== null;
            $hasTotals = $this->extractMarket($oddsData, 'totals') !== null || is_numeric(data_get($prediction->model_metadata, 'market_context.market_total'));

            $h2h += $hasH2h ? 1 : 0;
            $spreads += $hasSpreads ? 1 : 0;
            $totals += $hasTotals ? 1 : 0;

            $updatedAt = $game?->odds_updated_at ?? null;
            if ($updatedAt && Carbon::parse($updatedAt)->lt(now()->subHours((int) config('mlb.signals.odds_stale_hours', 12)))) {
                $stale++;
            }

            if (! $hasH2h || ! $hasSpreads || ! $hasTotals) {
                $missing[] = [
                    'game_id' => (int) ($game?->id ?? 0),
                    'matchup' => (string) ($game?->short_name ?: $game?->name ?: ''),
                    'missing' => array_values(array_filter([
                        ! $hasH2h ? 'moneyline' : null,
                        ! $hasSpreads ? 'run_line' : null,
                        ! $hasTotals ? 'total' : null,
                    ])),
                ];
            }
        }

        $coverage = fn (int $count): float => $total > 0 ? round($count / $total * 100, 1) : 0.0;
        $moneylineOnlyMode = (bool) config('mlb.signals.bet_filter.moneyline_enabled', true)
            && ! (bool) config('mlb.signals.bet_filter.run_line_enabled', false)
            && ! (bool) config('mlb.signals.bet_filter.total_enabled', false);

        $status = match (true) {
            $total === 0 => 'no_slate',
            $coverage($h2h) < 80.0 => 'unhealthy',
            $moneylineOnlyMode => $coverage($h2h) >= 95.0 ? 'moneyline_ready' : 'degraded',
            $coverage($h2h) < 100.0 || $coverage($spreads) < 80.0 || $coverage($totals) < 80.0 => 'degraded',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'primary_market' => $moneylineOnlyMode ? 'moneyline' : 'all_enabled_markets',
            'moneyline_ready' => $total > 0 && $coverage($h2h) >= 80.0,
            'slate_games' => $total,
            'moneyline_coverage' => $coverage($h2h),
            'run_line_coverage' => $coverage($spreads),
            'total_coverage' => $coverage($totals),
            'stale_games' => $stale,
            'missing_markets' => array_slice($missing, 0, 12),
        ];
    }

    protected function targetSlateDate(int $season, CarbonInterface $asOfDate): ?CarbonInterface
    {
        $today = Carbon::parse($asOfDate)->toDateString();
        $upcoming = Game::query()
            ->where('season', $season)
            ->where('status', config('mlb.statuses.scheduled'))
            ->whereDate('game_date', '>=', $today)
            ->orderBy('game_date')
            ->value('game_date');

        return $upcoming ? Carbon::parse($upcoming) : null;
    }

    /**
     * @return array<int,Prediction>
     */
    protected function slatePredictionRows(int $season, CarbonInterface $slateDate): array
    {
        return Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->where('season', $season)
            ->whereHas('game', function ($query) use ($slateDate): void {
                $query->whereDate('game_date', $slateDate->toDateString())
                    ->where('status', config('mlb.statuses.scheduled'));
            })
            ->get()
            ->filter(fn (Prediction $prediction): bool => $prediction->game !== null)
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function worldSeriesSignals(int $season): array
    {
        $rows = PlayoffForecast::query()
            ->with('team')
            ->where('season', $season)
            ->orderByDesc('champion_probability')
            ->orderByDesc('playoff_make_probability')
            ->get()
            ->map(fn (PlayoffForecast $forecast): array => [
                'type' => 'world_series',
                'team_id' => (int) $forecast->team_id,
                'team_name' => $this->teamName($forecast->team),
                'league' => $forecast->league,
                'league_rank' => (int) $forecast->league_rank,
                'playoff_make_probability' => (float) $forecast->playoff_make_probability,
                'world_series_probability' => (float) $forecast->world_series_probability,
                'champion_probability' => (float) $forecast->champion_probability,
                'selection_score' => (float) $forecast->selection_score,
            ])
            ->all();

        $marketOddsByTeam = $this->futuresOddsLookup->byTeamForSeason('mlb', $season);
        $rows = array_map(function (array $row) use ($marketOddsByTeam): array {
            $teamId = (int) ($row['team_id'] ?? 0);
            $row['market_odds'] = $marketOddsByTeam[$teamId] ?? null;

            return $row;
        }, $rows);
        $rows = $this->futuresEdgeService->annotate($rows, 'champion_probability');

        usort($rows, fn (array $left, array $right): int => $this->worldSeriesSortScore($right) <=> $this->worldSeriesSortScore($left));

        return array_map(function (array $row): array {
            $row['signal'] = $this->worldSeriesSignalLabel($row);
            $row['reason_codes'] = $this->worldSeriesReasonCodes($row);

            return $row;
        }, array_slice($rows, 0, 10));
    }

    protected function worldSeriesSortScore(array $row): float
    {
        return ((float) ($row['champion_probability'] ?? 0.0) * 1000)
            + ((float) data_get($row, 'market_edge.edge_percent_points', 0.0) * 0.5);
    }

    protected function worldSeriesSignalLabel(array $row): string
    {
        $probability = (float) ($row['champion_probability'] ?? 0.0);
        $edge = (float) data_get($row, 'market_edge.edge_percent_points', 0.0);

        return match (true) {
            $edge >= 3.0 && $probability >= 0.06 => 'value_contender',
            $probability >= 0.10 => 'model_contender',
            $edge >= 2.0 => 'value_watchlist',
            default => 'watchlist',
        };
    }

    /**
     * @return list<string>
     */
    protected function worldSeriesReasonCodes(array $row): array
    {
        $codes = ['world_series_futures_signal'];
        if ((float) ($row['champion_probability'] ?? 0.0) >= 0.10) {
            $codes[] = 'world_series_model_contender';
        }
        if ((float) ($row['playoff_make_probability'] ?? 0.0) >= 0.70) {
            $codes[] = 'playoff_probability_anchor';
        }
        if ((float) data_get($row, 'market_edge.edge_percent_points', 0.0) >= 3.0) {
            $codes[] = 'world_series_market_value';
        }

        return $codes;
    }

    /**
     * @param  array<int,Prediction>  $predictions
     * @return array<int,array<string,mixed>>
     */
    protected function moneylineSignals(array $predictions): array
    {
        $signals = array_map(function (Prediction $prediction): array {
            $game = $prediction->game;
            $homeWinProbability = (float) $prediction->win_probability;
            $pickSide = $homeWinProbability >= 0.5 ? 'home' : 'away';
            $pickTeam = $pickSide === 'home' ? $game->homeTeam : $game->awayTeam;

            return [
                'type' => 'moneyline',
                'game_id' => (int) $game->id,
                'game_date' => $game->game_date?->toDateString(),
                'matchup' => (string) ($game->short_name ?: $game->name),
                'pick_side' => $pickSide,
                'team_id' => (int) ($pickTeam?->id ?? 0),
                'team_name' => $this->teamName($pickTeam),
                'win_probability' => round(max($homeWinProbability, 1 - $homeWinProbability), 4),
                'confidence_score' => (float) $prediction->confidence_score,
                'reason_codes' => $this->predictionReasonCodes($prediction, 'moneyline_signal'),
            ];
        }, $predictions);

        usort($signals, fn (array $left, array $right): int => ($right['win_probability'] <=> $left['win_probability'])
            ?: ($right['confidence_score'] <=> $left['confidence_score']));

        return array_slice($signals, 0, 10);
    }

    /**
     * @param  array<int,Prediction>  $predictions
     * @return array<int,array<string,mixed>>
     */
    protected function runLineSignals(array $predictions): array
    {
        $signals = [];

        foreach ($predictions as $prediction) {
            if ($prediction->vegas_spread === null) {
                continue;
            }

            $edge = $this->runLineEdge($prediction);
            if (abs($edge) < (float) config('mlb.signals.run_line_min_edge', 0.75)) {
                continue;
            }

            $game = $prediction->game;
            $pickSide = $edge > 0 ? 'home' : 'away';
            $pickTeam = $pickSide === 'home' ? $game->homeTeam : $game->awayTeam;

            $signals[] = [
                'type' => 'run_line',
                'game_id' => (int) $game->id,
                'game_date' => $game->game_date?->toDateString(),
                'matchup' => (string) ($game->short_name ?: $game->name),
                'pick_side' => $pickSide,
                'team_id' => (int) ($pickTeam?->id ?? 0),
                'team_name' => $this->teamName($pickTeam),
                'predicted_spread' => (float) $prediction->predicted_spread,
                'vegas_spread' => (float) $prediction->vegas_spread,
                'edge_runs' => round(abs($edge), 2),
                'reason_codes' => $this->predictionReasonCodes($prediction, $pickSide.'_run_line_edge'),
            ];
        }

        usort($signals, fn (array $left, array $right): int => $right['edge_runs'] <=> $left['edge_runs']);

        return array_slice($signals, 0, 10);
    }

    /**
     * @param  array<int,Prediction>  $predictions
     * @return array<int,array<string,mixed>>
     */
    protected function totalSignals(array $predictions): array
    {
        $signals = [];

        foreach ($predictions as $prediction) {
            $marketTotal = data_get($prediction->model_metadata, 'market_context.market_total');
            if (! is_numeric($marketTotal)) {
                continue;
            }

            $edge = (float) $prediction->predicted_total - (float) $marketTotal;
            if (abs($edge) < (float) config('mlb.signals.total_min_edge', 0.75)) {
                continue;
            }

            $game = $prediction->game;
            $signals[] = [
                'type' => 'total',
                'game_id' => (int) $game->id,
                'game_date' => $game->game_date?->toDateString(),
                'matchup' => (string) ($game->short_name ?: $game->name),
                'pick_side' => $edge > 0 ? 'over' : 'under',
                'predicted_total' => (float) $prediction->predicted_total,
                'market_total' => (float) $marketTotal,
                'edge_runs' => round(abs($edge), 2),
                'reason_codes' => $this->predictionReasonCodes($prediction, $edge > 0 ? 'total_over_edge' : 'total_under_edge'),
            ];
        }

        usort($signals, fn (array $left, array $right): int => $right['edge_runs'] <=> $left['edge_runs']);

        return array_slice($signals, 0, 10);
    }

    /**
     * @param  array<int,Prediction>  $predictions
     * @return array<int,array<string,mixed>>
     */
    protected function ballparkSignals(array $predictions): array
    {
        $signals = [];

        foreach ($predictions as $prediction) {
            $game = $prediction->game;
            if (! $game) {
                continue;
            }

            $parkContext = (array) data_get($prediction->model_metadata, 'park_context', []);
            $venueName = (string) ($parkContext['venue_name'] ?? $game->venue_name ?? '');
            if ($venueName === '') {
                continue;
            }

            $totalAdjustment = (float) ($parkContext['total_adjustment'] ?? 0.0);
            $pickSide = ((float) $prediction->win_probability) >= 0.5 ? 'home' : 'away';
            $pickTeam = $pickSide === 'home' ? $game->homeTeam : $game->awayTeam;
            $weatherSignal = (string) ($parkContext['weather_signal'] ?? 'weather_not_available');

            $signals[] = [
                'type' => 'ballpark',
                'game_id' => (int) $game->id,
                'game_date' => $game->game_date?->toDateString(),
                'matchup' => (string) ($game->short_name ?: $game->name),
                'venue_name' => $venueName,
                'pick_side' => $pickSide,
                'team_id' => (int) ($pickTeam?->id ?? 0),
                'team_name' => $this->teamName($pickTeam),
                'run_environment' => (string) ($parkContext['run_environment'] ?? $this->ballparkRunEnvironment($totalAdjustment)),
                'runs_signal' => (string) ($parkContext['runs_signal'] ?? $this->ballparkRunsSignal($totalAdjustment)),
                'home_run_signal' => (string) ($parkContext['home_run_signal'] ?? $this->ballparkHomeRunSignal($totalAdjustment)),
                'win_signal' => (string) ($parkContext['win_signal'] ?? $this->ballparkWinSignal($totalAdjustment)),
                'weather_signal' => $weatherSignal,
                'total_adjustment' => round($totalAdjustment, 2),
                'predicted_total' => (float) $prediction->predicted_total,
                'confidence_score' => (float) $prediction->confidence_score,
                'reason_codes' => $this->ballparkReasonCodes($totalAdjustment, $weatherSignal),
            ];
        }

        usort($signals, fn (array $left, array $right): int => abs((float) $right['total_adjustment']) <=> abs((float) $left['total_adjustment']));

        return array_slice($signals, 0, 10);
    }

    /**
     * Live rows are monitoring signals only. They should show the operator
     * current model state without feeding the pregame bet filter.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function liveSignals(int $season, CarbonInterface $asOfDate): array
    {
        $window = $this->dateWindows->forRange(
            Carbon::parse($asOfDate)->subDay(),
            Carbon::parse($asOfDate)->addDay()
        );
        $liveStatuses = array_values(array_filter([
            config('mlb.statuses.in_progress'),
        ]));

        $query = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->where('season', $season)
            ->whereHas('game', function ($query) use ($liveStatuses, $window): void {
                $query->whereIn('status', $liveStatuses);
                $this->dateWindows->applyGameDateWindow($query, $window);
            });

        return $query
            ->get()
            ->filter(fn (Prediction $prediction): bool => $prediction->game !== null)
            ->map(fn (Prediction $prediction): array => $this->liveSignalRow($prediction))
            ->sortByDesc(fn (array $row): float => (float) ($row['live_probability_delta'] ?? 0.0))
            ->values()
            ->take(12)
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    protected function liveSignalRow(Prediction $prediction): array
    {
        $game = $prediction->game;
        $liveWinProbability = $prediction->live_win_probability !== null
            ? (float) $prediction->live_win_probability
            : null;
        $pregameWinProbability = $prediction->win_probability !== null
            ? (float) $prediction->win_probability
            : null;
        $pickSide = ($liveWinProbability ?? $pregameWinProbability ?? 0.5) >= 0.5 ? 'home' : 'away';
        $pickTeam = $pickSide === 'home' ? $game?->homeTeam : $game?->awayTeam;
        $updatedAt = $prediction->live_updated_at ? Carbon::parse($prediction->live_updated_at) : null;
        $staleMinutes = (int) config('mlb.signals.live_stale_minutes', 6);
        $isStale = $updatedAt === null || $updatedAt->lt(now()->subMinutes($staleMinutes));
        $probabilityDelta = $liveWinProbability !== null && $pregameWinProbability !== null
            ? abs($liveWinProbability - $pregameWinProbability)
            : null;

        return [
            'type' => 'live',
            'game_id' => (int) ($game?->id ?? 0),
            'game_date' => $game?->game_date?->toDateString(),
            'matchup' => (string) ($game?->short_name ?: $game?->name ?: ''),
            'status' => (string) ($game?->status ?? ''),
            'inning' => $game?->inning !== null ? (int) $game->inning : null,
            'inning_state' => $game?->inning_state,
            'home_score' => $game?->home_score !== null ? (int) $game->home_score : null,
            'away_score' => $game?->away_score !== null ? (int) $game->away_score : null,
            'pick_side' => $pickSide,
            'team_id' => (int) ($pickTeam?->id ?? 0),
            'team_name' => $this->teamName($pickTeam),
            'pregame_win_probability' => $pregameWinProbability !== null ? round($pregameWinProbability, 4) : null,
            'live_win_probability' => $liveWinProbability !== null ? round($liveWinProbability, 4) : null,
            'live_probability_delta' => $probabilityDelta !== null ? round($probabilityDelta, 4) : null,
            'live_predicted_spread' => $prediction->live_predicted_spread !== null ? (float) $prediction->live_predicted_spread : null,
            'live_predicted_total' => $prediction->live_predicted_total !== null ? (float) $prediction->live_predicted_total : null,
            'live_outs_remaining' => $prediction->live_outs_remaining !== null ? (int) $prediction->live_outs_remaining : null,
            'live_updated_at' => $updatedAt?->toIso8601String(),
            'is_stale' => $isStale,
            'signal' => $this->liveSignalLabel($prediction, $isStale, $probabilityDelta),
            'reason_codes' => $this->liveSignalReasons($prediction, $isStale, $probabilityDelta),
            'risk_flags' => $isStale ? ['live_model_stale'] : [],
        ];
    }

    protected function liveSignalLabel(Prediction $prediction, bool $isStale, ?float $probabilityDelta): string
    {
        $status = (string) ($prediction->game?->status ?? '');

        return match (true) {
            $prediction->live_win_probability === null => 'live_model_missing',
            $isStale => 'stale_live_model',
            $status === config('mlb.statuses.delayed') => 'delayed_game_monitor',
            ($probabilityDelta ?? 0.0) >= 0.20 => 'major_live_swing',
            max((float) $prediction->live_win_probability, 1 - (float) $prediction->live_win_probability) >= 0.75 => 'live_control',
            default => 'live_monitor',
        };
    }

    /**
     * @return list<string>
     */
    protected function liveSignalReasons(Prediction $prediction, bool $isStale, ?float $probabilityDelta): array
    {
        $reasons = ['live_prediction_monitor'];

        if ($prediction->live_win_probability !== null) {
            $reasons[] = 'live_win_probability_available';
        }

        if ($prediction->live_predicted_spread !== null) {
            $reasons[] = 'live_spread_available';
        }

        if ($prediction->live_predicted_total !== null) {
            $reasons[] = 'live_total_available';
        }

        if (($probabilityDelta ?? 0.0) >= 0.20) {
            $reasons[] = 'major_pregame_to_live_probability_move';
        }

        if ($isStale) {
            $reasons[] = 'live_model_stale';
        }

        if (($prediction->game?->status ?? null) === config('mlb.statuses.delayed')) {
            $reasons[] = 'game_delayed';
        }

        return array_values(array_unique($reasons));
    }

    protected function runLineEdge(Prediction $prediction): float
    {
        return MlbMarketSpread::edgeRuns((float) $prediction->predicted_spread, (float) $prediction->vegas_spread);
    }

    /**
     * @return array<string,mixed>
     */
    protected function betFilterSummary(): array
    {
        $moneylineOnlyMode = (bool) config('mlb.signals.bet_filter.moneyline_enabled', true)
            && ! (bool) config('mlb.signals.bet_filter.run_line_enabled', false)
            && ! (bool) config('mlb.signals.bet_filter.total_enabled', false);

        return [
            'model' => self::FILTER_VERSION,
            'mode' => $moneylineOnlyMode ? 'moneyline_first' : 'multi_market',
            'primary_market' => $moneylineOnlyMode ? 'moneyline' : 'enabled_markets',
            'philosophy' => $moneylineOnlyMode
                ? 'use_mlb_as_moneyline_first_until_run_line_and_total_backtests_are_trusted'
                : 'pass_most_games_until_model_strength_market_edge_and_pitcher_context_align',
            'thresholds' => [
                'strong_min_score' => (int) config('mlb.signals.bet_filter.strong_min_score', 70),
                'lean_min_score' => (int) config('mlb.signals.bet_filter.lean_min_score', 55),
                'min_confidence' => (float) config('mlb.signals.bet_filter.min_confidence', 55),
                'strong_confidence' => (float) config('mlb.signals.bet_filter.strong_confidence', 60),
                'min_model_spread' => (float) config('mlb.signals.bet_filter.min_model_spread', 1.0),
                'strong_model_spread' => (float) config('mlb.signals.bet_filter.strong_model_spread', 1.5),
                'min_run_line_edge' => (float) config('mlb.signals.bet_filter.min_run_line_edge', 1.0),
                'min_total_edge' => (float) config('mlb.signals.bet_filter.min_total_edge', 1.25),
            ],
            'risk_controls' => [
                'moneyline_can_stand_alone_when_h2h_prices_are_available',
                'downgrade_away_moneyline_picks_until_away_split_improves',
                'downgrade_pitcher_uncertainty',
                'require_market_total_for_total_bets',
                'require_market_spread_for_run_line_bets',
                'do_not_promote_run_line_or_total_as_bets_until_backtest_validates_them',
                'classify_low_score_games_as_pass_even_when_model_has_a_pick',
            ],
            'enabled_markets' => [
                'moneyline' => (bool) config('mlb.signals.bet_filter.moneyline_enabled', true),
                'run_line' => (bool) config('mlb.signals.bet_filter.run_line_enabled', false),
                'total' => (bool) config('mlb.signals.bet_filter.total_enabled', false),
            ],
            'promotion' => $this->recommendationPromotionSummary(),
        ];
    }

    /**
     * @param  array<int,Prediction>  $predictions
     * @return array<string,mixed>
     */
    protected function moneylineReadiness(array $predictions): array
    {
        $candidates = [];

        foreach ($predictions as $prediction) {
            $candidate = $this->moneylineBetCandidate($prediction);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        $counts = [
            'bet' => 0,
            'lean' => 0,
            'pass' => 0,
        ];
        $priced = 0;
        $positiveMarketEdges = 0;
        $publicUsable = 0;
        $topPassReasons = [];

        foreach ($candidates as $candidate) {
            $classification = (string) ($candidate['classification'] ?? 'pass');
            $counts[$classification] = ($counts[$classification] ?? 0) + 1;

            if (in_array($classification, ['bet', 'lean'], true) && $this->publicBetCandidatePromotable($candidate)) {
                $publicUsable++;
            }

            if (($candidate['market_price'] ?? null) !== null) {
                $priced++;
            }

            if ((float) ($candidate['probability_edge'] ?? 0.0) > 0.0) {
                $positiveMarketEdges++;
            }

            if ($classification === 'pass') {
                $reason = (string) ($candidate['no_bet_reason'] ?? 'score_below_threshold');
                $topPassReasons[$reason] = ($topPassReasons[$reason] ?? 0) + 1;
            }
        }

        arsort($topPassReasons);

        return [
            'mode' => 'moneyline_first',
            'slate_games' => count($predictions),
            'candidate_count' => count($candidates),
            'priced_count' => $priced,
            'priced_rate' => count($candidates) > 0 ? round($priced / count($candidates) * 100, 1) : 0.0,
            'bet_count' => $counts['bet'] ?? 0,
            'lean_count' => $counts['lean'] ?? 0,
            'pass_count' => $counts['pass'] ?? 0,
            'public_bet_count' => $this->publicRecommendationPromotionEnabled() ? $this->publicClassificationCount($candidates, 'bet') : 0,
            'public_lean_count' => $this->publicRecommendationPromotionEnabled() ? $this->publicClassificationCount($candidates, 'lean') : 0,
            'shadow_bet_count' => $counts['bet'] ?? 0,
            'shadow_lean_count' => $counts['lean'] ?? 0,
            'positive_market_edge_count' => $positiveMarketEdges,
            'usable_count' => ($counts['bet'] ?? 0) + ($counts['lean'] ?? 0),
            'public_usable_count' => $this->publicRecommendationPromotionEnabled() ? $publicUsable : 0,
            'shadow_usable_count' => ($counts['bet'] ?? 0) + ($counts['lean'] ?? 0),
            'top_pass_reasons' => array_map(
                fn (string $reason, int $count): array => ['reason' => $reason, 'count' => $count],
                array_keys($topPassReasons),
                array_values($topPassReasons)
            ),
        ];
    }

    /**
     * @param  array<int,Prediction>  $predictions
     * @return array<int,array<string,mixed>>
     */
    protected function recommendedBets(array $predictions): array
    {
        return $this->publicRecommendedBets($this->candidateRecommendedBets($predictions));
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidateBets
     * @return array<int,array<string,mixed>>
     */
    protected function publicRecommendedBets(array $candidateBets): array
    {
        if (! $this->publicRecommendationPromotionEnabled()) {
            return [];
        }

        return array_values(array_filter($candidateBets, fn (array $candidate): bool => $this->publicBetCandidatePromotable($candidate)));
    }

    /**
     * @param  array<int,Prediction>  $predictions
     * @return array<int,array<string,mixed>>
     */
    protected function candidateRecommendedBets(array $predictions): array
    {
        $recommendations = [];

        foreach ($predictions as $prediction) {
            array_push($recommendations, ...$this->betCandidatesForPrediction($prediction, enabledOnly: true, includePasses: false));
        }

        $recommendations = array_values(array_filter(
            $recommendations,
            fn (?array $candidate): bool => $candidate !== null && ($candidate['classification'] ?? 'pass') !== 'pass'
        ));

        usort($recommendations, fn (array $left, array $right): int => ((int) $right['score'] <=> (int) $left['score'])
            ?: ((float) ($right['edge_runs'] ?? 0.0) <=> (float) ($left['edge_runs'] ?? 0.0)));

        return array_slice($recommendations, 0, (int) config('mlb.signals.bet_filter.max_recommendations', 8));
    }

    /**
     * @return array<string,mixed>
     */
    protected function recommendationPromotionSummary(): array
    {
        $guardEnabled = (bool) config('mlb.signals.bet_filter.calibration_guard_enabled', true);
        $promotionsValidated = (bool) config('mlb.signals.bet_filter.promotions_validated', false);
        $enabled = $this->publicRecommendationPromotionEnabled();

        return [
            'status' => $enabled ? 'enabled' : 'blocked',
            'public_recommendations_enabled' => $enabled,
            'shadow_research_enabled' => true,
            'calibration_guard_enabled' => $guardEnabled,
            'promotions_validated' => $promotionsValidated,
            'block_reasons' => $enabled ? [] : ['recommendation_calibration_unvalidated'],
        ];
    }

    protected function publicRecommendationPromotionEnabled(): bool
    {
        return ! (bool) config('mlb.signals.bet_filter.calibration_guard_enabled', true)
            || (bool) config('mlb.signals.bet_filter.promotions_validated', false);
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     */
    protected function publicClassificationCount(array $candidates, string $classification): int
    {
        return count(array_filter(
            $candidates,
            fn (array $candidate): bool => ($candidate['classification'] ?? null) === $classification
                && $this->publicBetCandidatePromotable($candidate)
        ));
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    protected function publicBetCandidatePromotable(array $candidate): bool
    {
        if (! $this->publicRecommendationPromotionEnabled()) {
            return false;
        }

        return ! in_array('model_market_disagreement_unvalidated', (array) ($candidate['risk_flags'] ?? []), true);
    }

    /**
     * @param  array<int,Prediction>  $predictions
     * @return array<string,mixed>
     */
    protected function passSummary(array $predictions): array
    {
        $candidates = [];
        foreach ($predictions as $prediction) {
            array_push($candidates, ...$this->betCandidatesForPrediction($prediction, enabledOnly: true, includePasses: true));
        }

        $passes = array_values(array_filter($candidates, fn (array $candidate): bool => ($candidate['classification'] ?? null) === 'pass'));
        $reasonCounts = [];
        foreach ($passes as $pass) {
            $reason = (string) ($pass['no_bet_reason'] ?? 'score_below_threshold');
            $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
        }
        arsort($reasonCounts);

        return [
            'candidates' => count($candidates),
            'passes' => count($passes),
            'pass_rate' => count($candidates) > 0 ? round(count($passes) / count($candidates) * 100, 1) : 0.0,
            'top_reasons' => array_map(
                fn (string $reason, int $count): array => ['reason' => $reason, 'count' => $count],
                array_keys($reasonCounts),
                array_values($reasonCounts)
            ),
            'sample' => array_slice(array_map(fn (array $candidate): array => [
                'game_id' => $candidate['game_id'] ?? null,
                'matchup' => $candidate['matchup'] ?? null,
                'market' => $candidate['type'] ?? null,
                'pick_side' => $candidate['pick_side'] ?? null,
                'score' => $candidate['score'] ?? null,
                'no_bet_reason' => $candidate['no_bet_reason'] ?? null,
                'risk_flags' => $candidate['risk_flags'] ?? [],
            ], $passes), 0, 8),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function betCandidatesForPrediction(Prediction $prediction, bool $enabledOnly = true, bool $includePasses = true): array
    {
        $candidates = [];

        if (! $enabledOnly || (bool) config('mlb.signals.bet_filter.moneyline_enabled', true)) {
            $candidates[] = $this->moneylineBetCandidate($prediction);
        }

        if ((! $enabledOnly || (bool) config('mlb.signals.bet_filter.run_line_enabled', false)) && $prediction->vegas_spread !== null) {
            $candidates[] = $this->runLineBetCandidate($prediction);
        }

        if ((! $enabledOnly || (bool) config('mlb.signals.bet_filter.total_enabled', false)) && is_numeric(data_get($prediction->model_metadata, 'market_context.market_total'))) {
            $candidates[] = $this->totalBetCandidate($prediction);
        }

        return array_values(array_filter(
            $candidates,
            fn (?array $candidate): bool => $candidate !== null && ($includePasses || ($candidate['classification'] ?? 'pass') !== 'pass')
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function moneylineBetCandidate(Prediction $prediction): ?array
    {
        $game = $prediction->game;
        if (! $game) {
            return null;
        }

        $homeWinProbability = (float) $prediction->win_probability;
        $pickSide = $homeWinProbability >= 0.5 ? 'home' : 'away';
        $pickTeam = $pickSide === 'home' ? $game->homeTeam : $game->awayTeam;
        $edgeRuns = abs((float) $prediction->predicted_spread);
        $modelProbability = max($homeWinProbability, 1 - $homeWinProbability);
        $marketPrice = $this->moneylinePrice($prediction, $pickSide);
        $prices = $this->moneylinePrices($prediction);
        $marketImplied = $marketPrice !== null ? $this->americanToImpliedProbability($marketPrice) : null;
        $probabilityEdge = $marketImplied !== null ? $modelProbability - $marketImplied : null;
        $noVigProbabilities = AmericanOdds::noVigProbabilities($prices['home'] ?? null, $prices['away'] ?? null);
        $noVigImplied = $noVigProbabilities[$pickSide] ?? null;
        $noVigEdge = $noVigImplied !== null ? $modelProbability - $noVigImplied : null;
        $marketFavoriteSide = $this->moneylineMarketFavoriteSide($prices, $noVigProbabilities);
        $codes = $this->predictionReasonCodes($prediction, 'moneyline_bet_filter');
        [$score, $reasons, $riskFlags] = $this->baseBetScore($prediction, $pickSide, $codes);
        $riskFlags = array_values(array_unique([...$riskFlags, ...$this->oddsTimestampRiskFlags($prediction)]));
        $riskFlags = array_values(array_unique([...$riskFlags, ...$this->contextRiskFlags($prediction, $marketPrice)]));

        if ($marketFavoriteSide !== null && $marketFavoriteSide !== $pickSide) {
            $riskFlags[] = 'model_market_disagreement_unvalidated';
            $reasons[] = 'model_market_disagreement_context';
        }

        $score += $this->scoreModelSpread($edgeRuns, $reasons);
        $score += $this->scoreWinProbability($modelProbability, $reasons);

        if ($probabilityEdge !== null) {
            if ($probabilityEdge >= 0.035) {
                $score += 14;
                $reasons[] = 'moneyline_market_value';
            } elseif ($probabilityEdge >= 0.015) {
                $score += 6;
                $reasons[] = 'thin_moneyline_market_value';
            } else {
                $score -= 14;
                $riskFlags[] = 'no_moneyline_market_value';
            }
        } else {
            $riskFlags[] = 'moneyline_price_missing';
        }

        return $this->finalizeBetCandidate([
            'type' => 'moneyline',
            'game_id' => (int) $game->id,
            'game_date' => $game->game_date?->toDateString(),
            'matchup' => (string) ($game->short_name ?: $game->name),
            'pick_side' => $pickSide,
            'team_id' => (int) ($pickTeam?->id ?? 0),
            'team_name' => $this->teamName($pickTeam),
            'win_probability' => round($modelProbability, 4),
            'model_probability' => round($modelProbability, 4),
            'market_price' => $marketPrice,
            'market_implied_probability' => $marketImplied !== null ? round($marketImplied, 4) : null,
            'no_vig_implied_probability' => $noVigImplied !== null ? round($noVigImplied, 4) : null,
            'market_favorite_side' => $marketFavoriteSide,
            'probability_edge' => $probabilityEdge !== null ? round($probabilityEdge, 4) : null,
            'no_vig_edge' => $noVigEdge !== null ? round($noVigEdge, 4) : null,
            'confidence_score' => (float) $prediction->confidence_score,
            'edge_runs' => round($edgeRuns, 2),
            'model_line' => round((float) $prediction->predicted_spread, 2),
            'reason_codes' => array_values(array_unique([...$codes, ...$reasons])),
            'risk_flags' => array_values(array_unique($riskFlags)),
        ], $score);
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function runLineBetCandidate(Prediction $prediction): ?array
    {
        $game = $prediction->game;
        if (! $game) {
            return null;
        }

        $edge = $this->runLineEdge($prediction);
        $pickSide = $edge > 0 ? 'home' : 'away';
        $pickTeam = $pickSide === 'home' ? $game->homeTeam : $game->awayTeam;
        $edgeRuns = abs($edge);
        $codes = $this->predictionReasonCodes($prediction, $pickSide.'_run_line_bet_filter');
        [$score, $reasons, $riskFlags] = $this->baseBetScore($prediction, $pickSide, $codes);

        if ($edgeRuns >= (float) config('mlb.signals.bet_filter.min_run_line_edge', 1.0)) {
            $score += $edgeRuns >= 1.5 ? 20 : 12;
            $reasons[] = 'run_line_market_edge';
        } else {
            $score -= 25;
            $riskFlags[] = 'run_line_edge_below_threshold';
        }

        return $this->finalizeBetCandidate([
            'type' => 'run_line',
            'game_id' => (int) $game->id,
            'game_date' => $game->game_date?->toDateString(),
            'matchup' => (string) ($game->short_name ?: $game->name),
            'pick_side' => $pickSide,
            'team_id' => (int) ($pickTeam?->id ?? 0),
            'team_name' => $this->teamName($pickTeam),
            'predicted_spread' => (float) $prediction->predicted_spread,
            'vegas_spread' => (float) $prediction->vegas_spread,
            'model_line' => round((float) $prediction->predicted_spread, 2),
            'market_line' => round(MlbMarketSpread::marketLineFromHomeSpread((float) $prediction->vegas_spread), 2),
            'confidence_score' => (float) $prediction->confidence_score,
            'edge_runs' => round($edgeRuns, 2),
            'reason_codes' => array_values(array_unique([...$codes, ...$reasons])),
            'risk_flags' => array_values(array_unique($riskFlags)),
        ], $score);
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function totalBetCandidate(Prediction $prediction): ?array
    {
        $game = $prediction->game;
        $marketTotal = data_get($prediction->model_metadata, 'market_context.market_total');
        if (! $game || ! is_numeric($marketTotal)) {
            return null;
        }

        $edge = (float) $prediction->predicted_total - (float) $marketTotal;
        $edgeRuns = abs($edge);
        $pickSide = $edge > 0 ? 'over' : 'under';
        $codes = $this->predictionReasonCodes($prediction, $pickSide.'_total_bet_filter');
        [$score, $reasons, $riskFlags] = $this->baseBetScore($prediction, $pickSide, $codes);

        if ($edgeRuns >= (float) config('mlb.signals.bet_filter.min_total_edge', 1.25)) {
            $score += $edgeRuns >= 1.75 ? 20 : 12;
            $reasons[] = 'total_market_edge';
        } else {
            $score -= 25;
            $riskFlags[] = 'total_edge_below_threshold';
        }

        if ($pickSide === 'under' && in_array('park_factor_total_context', $codes, true)) {
            $score += 4;
            $reasons[] = 'park_context_supports_total_filter';
        }

        return $this->finalizeBetCandidate([
            'type' => 'total',
            'game_id' => (int) $game->id,
            'game_date' => $game->game_date?->toDateString(),
            'matchup' => (string) ($game->short_name ?: $game->name),
            'pick_side' => $pickSide,
            'predicted_total' => (float) $prediction->predicted_total,
            'market_total' => (float) $marketTotal,
            'model_line' => round((float) $prediction->predicted_total, 2),
            'market_line' => round((float) $marketTotal, 2),
            'confidence_score' => (float) $prediction->confidence_score,
            'edge_runs' => round($edgeRuns, 2),
            'reason_codes' => array_values(array_unique([...$codes, ...$reasons])),
            'risk_flags' => array_values(array_unique($riskFlags)),
        ], $score);
    }

    /**
     * @param  list<string>  $codes
     * @return array{0:int,1:list<string>,2:list<string>}
     */
    protected function baseBetScore(Prediction $prediction, string $pickSide, array $codes): array
    {
        $score = 30;
        $reasons = ['selective_bet_filter_applied'];
        $riskFlags = [];
        $confidence = (float) $prediction->confidence_score;

        if ($confidence >= (float) config('mlb.signals.bet_filter.strong_confidence', 60)) {
            $score += 20;
            $reasons[] = 'strong_confidence_bucket';
        } elseif ($confidence >= (float) config('mlb.signals.bet_filter.min_confidence', 55)) {
            $score += 10;
            $reasons[] = 'acceptable_confidence_bucket';
        } else {
            $score -= 20;
            $riskFlags[] = 'low_confidence_bucket';
        }

        if (in_array('probable_pitchers_confirmed', $codes, true)) {
            $score += 10;
            $reasons[] = 'pitcher_context_confirmed';
        }

        if (in_array('pitcher_uncertainty_risk', $codes, true)) {
            $score -= 18;
            $riskFlags[] = 'pitcher_uncertainty';
            $riskFlags[] = 'unconfirmed_pitcher';
        }

        if ($pickSide === 'home') {
            $score += 5;
            $reasons[] = 'home_pick_filter_bonus';
        } elseif ($pickSide === 'away') {
            $score -= 6;
            $riskFlags[] = 'away_pick_underperforming_split';
        }

        return [$score, $reasons, $riskFlags];
    }

    protected function scoreModelSpread(float $edgeRuns, array &$reasons): int
    {
        if ($edgeRuns >= (float) config('mlb.signals.bet_filter.strong_model_spread', 1.5)) {
            $reasons[] = 'strong_model_margin';

            return 16;
        }

        if ($edgeRuns >= (float) config('mlb.signals.bet_filter.min_model_spread', 1.0)) {
            $reasons[] = 'acceptable_model_margin';

            return 8;
        }

        return -8;
    }

    protected function scoreWinProbability(float $winProbability, array &$reasons): int
    {
        if ($winProbability >= 0.60) {
            $reasons[] = 'strong_win_probability';

            return 12;
        }

        if ($winProbability >= 0.55) {
            $reasons[] = 'acceptable_win_probability';

            return 6;
        }

        return -6;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    protected function finalizeBetCandidate(array $candidate, int $score): array
    {
        $score = max(0, min(100, $score));
        $strong = (int) config('mlb.signals.bet_filter.strong_min_score', 70);
        $lean = (int) config('mlb.signals.bet_filter.lean_min_score', 55);
        $riskFlags = (array) ($candidate['risk_flags'] ?? []);
        $forcePass = $this->hasDisqualifyingRisk($riskFlags);
        $blockOfficialBet = $this->hasOfficialBetBlockingRisk($riskFlags);

        $candidate['score'] = $score;
        $candidate['classification'] = match (true) {
            $forcePass => 'pass',
            $blockOfficialBet && $score >= $strong => $score >= $lean ? 'lean' : 'pass',
            $score >= $strong => 'bet',
            $score >= $lean => 'lean',
            default => 'pass',
        };
        $candidate['no_bet_reason'] = $candidate['classification'] === 'pass'
            ? $this->noBetReason((array) ($candidate['risk_flags'] ?? []))
            : null;

        return $candidate;
    }

    /**
     * @param  list<string>  $riskFlags
     */
    protected function hasDisqualifyingRisk(array $riskFlags): bool
    {
        foreach (['moneyline_price_missing', 'no_moneyline_market_value', 'model_market_disagreement_unvalidated', 'stale_odds', 'missing_odds_timestamp', 'run_line_edge_below_threshold', 'total_edge_below_threshold'] as $riskFlag) {
            if (in_array($riskFlag, $riskFlags, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $riskFlags
     */
    protected function hasOfficialBetBlockingRisk(array $riskFlags): bool
    {
        foreach (['stale_odds', 'missing_odds_timestamp'] as $riskFlag) {
            if (in_array($riskFlag, $riskFlags, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $riskFlags
     */
    protected function noBetReason(array $riskFlags): string
    {
        foreach (['moneyline_price_missing', 'no_moneyline_market_value', 'model_market_disagreement_unvalidated', 'run_line_edge_below_threshold', 'total_edge_below_threshold', 'stale_odds', 'missing_odds_timestamp'] as $priority) {
            if (in_array($priority, $riskFlags, true)) {
                return $priority;
            }
        }

        if ($riskFlags === []) {
            return 'score_below_threshold';
        }

        return (string) $riskFlags[0];
    }

    protected function moneylinePrice(Prediction $prediction, string $pickSide): ?int
    {
        $prices = $this->moneylinePrices($prediction);

        return $prices[$pickSide] ?? null;
    }

    /**
     * @return array{home:?int,away:?int}
     */
    protected function moneylinePrices(Prediction $prediction): array
    {
        $game = $prediction->game;
        $market = $this->extractMarket($game?->odds_data, 'h2h');
        if (! $game || ! $market) {
            return ['home' => null, 'away' => null];
        }

        $prices = ['home' => null, 'away' => null];

        foreach (($market['outcomes'] ?? []) as $outcome) {
            if (! is_array($outcome) || ! is_numeric($outcome['price'] ?? null)) {
                continue;
            }

            if ($this->teamMatchesOutcome($game->homeTeam, (string) ($outcome['name'] ?? ''))) {
                $prices['home'] = (int) $outcome['price'];
            }

            if ($this->teamMatchesOutcome($game->awayTeam, (string) ($outcome['name'] ?? ''))) {
                $prices['away'] = (int) $outcome['price'];
            }
        }

        return $prices;
    }

    /**
     * @param  array{home:?int,away:?int}  $prices
     * @param  array{home:?float,away:?float}  $noVigProbabilities
     */
    protected function moneylineMarketFavoriteSide(array $prices, array $noVigProbabilities): ?string
    {
        if ($noVigProbabilities['home'] !== null && $noVigProbabilities['away'] !== null) {
            if (abs((float) $noVigProbabilities['home'] - (float) $noVigProbabilities['away']) < 0.0001) {
                return null;
            }

            return $noVigProbabilities['home'] >= $noVigProbabilities['away'] ? 'home' : 'away';
        }

        if ($prices['home'] !== null && $prices['away'] !== null) {
            if ((int) $prices['home'] === (int) $prices['away']) {
                return null;
            }

            return $prices['home'] <= $prices['away'] ? 'home' : 'away';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function oddsTimestampRiskFlags(Prediction $prediction): array
    {
        $updatedAt = $prediction->game?->odds_updated_at;
        if ($updatedAt === null) {
            return ['missing_odds_timestamp'];
        }

        return Carbon::parse($updatedAt)->lt(now()->subHours((int) config('mlb.signals.odds_stale_hours', 12)))
            ? ['stale_odds']
            : [];
    }

    /**
     * @return list<string>
     */
    protected function contextRiskFlags(Prediction $prediction, ?int $marketPrice): array
    {
        $flags = [];

        if ($marketPrice === null) {
            $flags[] = 'missing_market_context';
        }

        if ((string) data_get($prediction->model_metadata, 'actual_weather.reason') === 'missing_weather_row') {
            $flags[] = 'missing_weather';
        }

        return $flags;
    }

    /**
     * @param  array<string,mixed>|null  $oddsData
     * @return array<string,mixed>|null
     */
    protected function extractMarket(?array $oddsData, string $marketKey): ?array
    {
        foreach (($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (is_array($market) && ($market['key'] ?? null) === $marketKey) {
                    return $market;
                }
            }
        }

        return null;
    }

    protected function teamMatchesOutcome(?Team $team, string $outcomeName): bool
    {
        if (! $team) {
            return false;
        }

        $candidates = array_filter([
            (string) ($team->display_name ?? ''),
            trim(((string) ($team->location ?? '')).' '.((string) ($team->name ?? ''))),
            (string) ($team->name ?? ''),
            (string) ($team->abbreviation ?? ''),
        ]);

        $normalizedOutcome = $this->normalizeTeamName($outcomeName);

        foreach ($candidates as $candidate) {
            if ($this->normalizeTeamName($candidate) === $normalizedOutcome) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeTeamName(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
    }

    public function americanToImpliedProbability(int $price): ?float
    {
        return AmericanOdds::impliedProbability($price);
    }

    /**
     * @return list<string>
     */
    protected function predictionReasonCodes(Prediction $prediction, string $baseCode): array
    {
        $metadata = (array) ($prediction->model_metadata ?? []);
        $codes = [$baseCode];
        $pitcherInputs = (array) data_get($metadata, 'pitcher_inputs', []);
        $situational = (array) data_get($metadata, 'situational_context', []);

        if (($pitcherInputs['home_source'] ?? null) === 'probable_starter' && ($pitcherInputs['away_source'] ?? null) === 'probable_starter') {
            $codes[] = 'probable_pitchers_confirmed';
        }

        $pitcherConfidence = min((float) ($pitcherInputs['home_confidence'] ?? 0.0), (float) ($pitcherInputs['away_confidence'] ?? 0.0));
        if ($pitcherConfidence < 0.9) {
            $codes[] = 'pitcher_uncertainty_risk';
        }

        $starterFormSpread = (float) data_get($situational, 'starter_form.spread_adjustment', 0.0);
        if (abs($starterFormSpread) >= 0.25) {
            $codes[] = $starterFormSpread > 0 ? 'starter_form_home_edge' : 'starter_form_away_edge';
        }

        $bullpenQualitySpread = (float) data_get($situational, 'bullpen_quality.spread_adjustment', 0.0);
        if (abs($bullpenQualitySpread) >= 0.25) {
            $codes[] = $bullpenQualitySpread > 0 ? 'bullpen_quality_home_edge' : 'bullpen_quality_away_edge';
        }

        $bullpenTotal = (float) data_get($situational, 'bullpen.total_adjustment', 0.0);
        if ($bullpenTotal >= 0.25) {
            $codes[] = 'bullpen_fatigue_over_context';
        }

        $handednessSpread = (float) data_get($situational, 'handedness.spread_adjustment', 0.0);
        if (abs($handednessSpread) >= 0.25) {
            $codes[] = $handednessSpread > 0 ? 'handedness_home_edge' : 'handedness_away_edge';
        }

        if (abs((float) data_get($metadata, 'park_context.total_adjustment', 0.0)) >= 0.25) {
            $codes[] = 'park_factor_total_context';
        }

        $weatherAdjustment = (float) data_get($metadata, 'actual_weather.total_adjustment', 0.0);
        if (abs($weatherAdjustment) >= 0.15) {
            $codes[] = 'actual_weather_total_adjustment';
            $codes[] = $weatherAdjustment > 0 ? 'weather_over_context' : 'weather_under_context';
        }

        foreach ((array) data_get($metadata, 'actual_weather.signals', []) as $weatherSignal) {
            if (is_string($weatherSignal) && $weatherSignal !== '') {
                $codes[] = $weatherSignal;
            }
        }

        $parkHomeRunSignal = (string) data_get($metadata, 'park_context.home_run_signal', '');
        if (in_array($parkHomeRunSignal, ['park_home_run_boost', 'park_home_run_suppression'], true)) {
            $codes[] = $parkHomeRunSignal;
        }

        $parkRunsSignal = (string) data_get($metadata, 'park_context.runs_signal', '');
        if (in_array($parkRunsSignal, ['park_runs_boost', 'park_runs_suppression'], true)) {
            $codes[] = $parkRunsSignal;
        }

        if ((bool) data_get($metadata, 'depth_chart_context.probable_pitcher_injury_applied', false)) {
            $codes[] = 'probable_pitcher_injury_context';
        }

        if ((bool) data_get($metadata, 'historical_context.available', false)) {
            $codes[] = 'historical_matchup_context';
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return list<string>
     */
    protected function ballparkReasonCodes(float $totalAdjustment, string $weatherSignal): array
    {
        $codes = ['ballpark_context'];

        if (abs($totalAdjustment) >= 0.25) {
            $codes[] = 'park_factor_total_context';
        }

        $codes[] = $this->ballparkRunsSignal($totalAdjustment);
        $codes[] = $this->ballparkHomeRunSignal($totalAdjustment);
        $codes[] = $this->ballparkWinSignal($totalAdjustment);

        if ($weatherSignal === 'weather_not_available') {
            $codes[] = 'weather_feed_missing';
        } elseif ($weatherSignal === 'roof_status_unknown') {
            $codes[] = 'roof_status_unknown';
        } elseif ($weatherSignal === 'roof_closed_neutralizes_weather') {
            $codes[] = 'roof_closed_neutralizes_weather';
        } elseif ($weatherSignal !== '' && $weatherSignal !== 'weather_neutral') {
            $codes[] = 'actual_weather_context';
            $codes[] = $weatherSignal;
        }

        return array_values(array_unique($codes));
    }

    protected function ballparkRunEnvironment(float $totalAdjustment): string
    {
        return match (true) {
            $totalAdjustment >= 0.75 => 'hitter_friendly',
            $totalAdjustment >= 0.25 => 'slightly_hitter_friendly',
            $totalAdjustment <= -0.75 => 'pitcher_friendly',
            $totalAdjustment <= -0.25 => 'slightly_pitcher_friendly',
            default => 'neutral',
        };
    }

    protected function ballparkRunsSignal(float $totalAdjustment): string
    {
        return match (true) {
            $totalAdjustment >= 0.25 => 'park_runs_boost',
            $totalAdjustment <= -0.25 => 'park_runs_suppression',
            default => 'park_runs_neutral',
        };
    }

    protected function ballparkHomeRunSignal(float $totalAdjustment): string
    {
        return match (true) {
            $totalAdjustment >= 0.75 => 'park_home_run_boost',
            $totalAdjustment <= -0.75 => 'park_home_run_suppression',
            abs($totalAdjustment) >= 0.25 => 'park_home_run_context',
            default => 'park_home_run_neutral',
        };
    }

    protected function ballparkWinSignal(float $totalAdjustment): string
    {
        return match (true) {
            abs($totalAdjustment) >= 0.75 => 'ballpark_can_amplify_matchup_variance',
            abs($totalAdjustment) >= 0.25 => 'ballpark_context_only',
            default => 'ballpark_neutral_for_wins',
        };
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function streakSignals(int $season, CarbonInterface $asOfDate): array
    {
        $signals = [];

        foreach (Team::query()->orderBy('abbreviation')->get() as $team) {
            foreach ([null, 'run_line', 'total'] as $market) {
                $streak = $this->teamResultStreak($season, (int) $team->id, $asOfDate, $market);
                if ($streak === null || (int) $streak['length'] < (int) config('mlb.signals.min_streak_length', 4)) {
                    continue;
                }

                $signals[] = [
                    ...$streak,
                    'team_id' => (int) $team->id,
                    'team_name' => $this->teamName($team),
                    'team_abbreviation' => (string) ($team->abbreviation ?? ''),
                    'season_context' => $season,
                ];
            }
        }

        usort($signals, fn (array $left, array $right): int => ($right['length'] <=> $left['length'])
            ?: strcmp((string) $left['team_abbreviation'], (string) $right['team_abbreviation']));

        return array_slice($signals, 0, 16);
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function teamResultStreak(int $season, int $teamId, CarbonInterface $asOfDate, ?string $market): ?array
    {
        $games = Game::query()
            ->with('prediction')
            ->where('season', $season)
            ->where('status', 'STATUS_FINAL')
            ->whereDate('game_date', '<=', $asOfDate->toDateString())
            ->where(function ($query) use ($teamId): void {
                $query->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
            })
            ->orderByDesc('game_date')
            ->orderByDesc('id')
            ->limit(16)
            ->get();

        $streakKey = null;
        $length = 0;
        $sample = [];

        foreach ($games as $game) {
            $result = match ($market) {
                'run_line' => $this->runLineResult($game, $teamId),
                'total' => $this->totalResult($game),
                default => $this->straightUpResult($game, $teamId),
            };

            if ($result === null) {
                continue;
            }

            $streakKey ??= $result;
            if ($result !== $streakKey) {
                break;
            }

            $length++;
            $sample[] = [
                'game_id' => (int) $game->id,
                'date' => $game->game_date?->toDateString(),
                'matchup' => (string) ($game->short_name ?: $game->name),
            ];
        }

        if ($streakKey === null || $length === 0) {
            return null;
        }

        return [
            'type' => match ($market) {
                'run_line' => 'run_line_streak',
                'total' => 'total_streak',
                default => 'straight_up_streak',
            },
            'streak' => $streakKey,
            'length' => $length,
            'label' => $this->streakLabel($market, $streakKey, $length),
            'reason_codes' => $this->streakReasonCodes($market, $streakKey),
            'sample_games' => $sample,
        ];
    }

    protected function straightUpResult(Game $game, int $teamId): ?string
    {
        if ($game->home_score === null || $game->away_score === null || (int) $game->home_score === (int) $game->away_score) {
            return null;
        }

        $homeWon = (int) $game->home_score > (int) $game->away_score;

        return ($game->home_team_id === $teamId) === $homeWon ? 'win' : 'loss';
    }

    protected function runLineResult(Game $game, int $teamId): ?string
    {
        $prediction = $game->prediction;
        if (! $prediction || $prediction->vegas_spread === null || $game->home_score === null || $game->away_score === null) {
            return null;
        }

        $homeMargin = (int) $game->home_score - (int) $game->away_score;
        $marketHomeMargin = -1 * (float) $prediction->vegas_spread;
        $homeCovered = $homeMargin > $marketHomeMargin;

        return ($game->home_team_id === $teamId) === $homeCovered ? 'cover' : 'failed_cover';
    }

    protected function totalResult(Game $game): ?string
    {
        $marketTotal = data_get($game->prediction?->model_metadata, 'market_context.market_total');
        if ($game->home_score === null || $game->away_score === null || ! is_numeric($marketTotal)) {
            return null;
        }

        $actualTotal = (int) $game->home_score + (int) $game->away_score;

        return $actualTotal > (float) $marketTotal ? 'over' : 'under';
    }

    protected function streakLabel(?string $market, string $streakKey, int $length): string
    {
        return match ($market) {
            'run_line' => $streakKey === 'cover' ? "{$length}-game run-line cover streak" : "{$length}-game failed-cover streak",
            'total' => "{$length}-game {$streakKey} streak",
            default => "{$length}-game {$streakKey} streak",
        };
    }

    /**
     * @return list<string>
     */
    protected function streakReasonCodes(?string $market, string $streakKey): array
    {
        return array_values(array_filter([
            'mlb_streak_watch_signal',
            $market === 'run_line' ? 'run_line_streak_context' : null,
            $market === 'total' ? 'total_streak_context' : null,
            $market === null ? 'straight_up_streak_context' : null,
            str_replace('-', '_', $streakKey).'_streak',
        ]));
    }

    protected function teamName(?Team $team): string
    {
        if (! $team) {
            return '';
        }

        $display = trim((string) ($team->display_name ?? ''));
        if ($display !== '') {
            return $display;
        }

        return trim(implode(' ', array_filter([$team->location, $team->name]))) ?: (string) ($team->abbreviation ?? $team->id);
    }
}
