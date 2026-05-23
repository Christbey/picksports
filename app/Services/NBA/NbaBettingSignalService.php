<?php

namespace App\Services\NBA;

use App\Models\NBA\Game;
use App\Models\NBA\PlayoffForecast;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Services\Sports\FuturesEdgeService;
use App\Services\Sports\FuturesOddsLookupService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class NbaBettingSignalService
{
    public const FILTER_VERSION = 'selective_nba_bet_filter_v1';

    public function __construct(
        protected FuturesOddsLookupService $futuresOddsLookup,
        protected FuturesEdgeService $futuresEdgeService,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function signals(int $season, ?CarbonInterface $asOfDate = null): array
    {
        $asOfDate ??= now();
        $slateDate = $this->targetSlateDate($season, $asOfDate);
        $slatePredictions = $slateDate !== null ? $this->slatePredictionRows($season, $slateDate) : [];

        return [
            'season' => $season,
            'as_of_date' => $asOfDate->toDateString(),
            'slate_date' => $slateDate?->toDateString(),
            'backend_review' => $this->backendReview(),
            'framework' => $this->frameworkSummary(),
            'odds_health' => $this->oddsHealth($slatePredictions),
            'finals' => $this->finalsSignals($season),
            'spread' => $this->spreadSignals($slatePredictions),
            'moneyline' => $this->moneylineSignals($slatePredictions),
            'totals' => $this->totalSignals($slatePredictions),
            'bet_filter' => $this->betFilterSummary(),
            'recommended_bets' => $this->recommendedBets($slatePredictions),
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
            'model' => 'nba_ensemble',
            'strengths' => [
                'elo_efficiency_form_blend',
                'home_away_strength_splits',
                'rest_and_back_to_back_context',
                'turnover_and_rebound_adjustments',
                'depth_chart_weighted_injury_context',
                'true_epa_rollout_metadata',
                'market_spread_blending',
                'win_probability_calibration_artifact_support',
                'historical_odds_snapshot_support',
            ],
            'watch_items' => [
                'totals_are_pass_only_until_low_total_bias_is_recalibrated',
                'spread_edges_need_rule_layer_not_raw_threshold_only',
                'moneyline_edges_require_current_prices_before_promotion',
                'injury_status_volatility_should_downgrade_late_scratches',
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
            'shared_with_mlb_nfl' => [
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
            'nba_specific_deviations' => [
                'spread_edges_are_primary_until_total_model_is_recalibrated',
                'rest_back_to_back_and_travel_spots_have_higher_weight',
                'rotation_and_depth_chart_injuries_drive_risk_flags',
                'pace_efficiency_and_market_total_bias_are_total_specific',
                'playoff_futures_use_nba_playoff_forecast_probabilities',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function betFilterSummary(): array
    {
        return [
            'model' => self::FILTER_VERSION,
            'philosophy' => 'promote_only_spreads_or_moneylines_when_market_edge_confidence_and_rotation_context_align',
            'thresholds' => [
                'strong_min_score' => (float) config('nba.signals.bet_filter.strong_min_score', 72),
                'lean_min_score' => (float) config('nba.signals.bet_filter.lean_min_score', 58),
                'min_confidence' => (float) config('nba.signals.bet_filter.min_confidence', 58),
                'strong_confidence' => (float) config('nba.signals.bet_filter.strong_confidence', 70),
                'min_spread_edge' => (float) config('nba.signals.bet_filter.min_spread_edge', 2.0),
                'strong_spread_edge' => (float) config('nba.signals.bet_filter.strong_spread_edge', 4.0),
                'min_moneyline_edge' => (float) config('nba.signals.bet_filter.min_moneyline_edge', 0.04),
                'min_total_edge' => (float) config('nba.signals.bet_filter.min_total_edge', 6.0),
            ],
            'enabled_markets' => [
                'moneyline' => (bool) config('nba.signals.bet_filter.moneyline_enabled', true),
                'spread' => (bool) config('nba.signals.bet_filter.spread_enabled', true),
                'total' => (bool) config('nba.signals.bet_filter.total_enabled', false),
            ],
            'risk_controls' => [
                'disable_total_promotion_until_backtest_bias_is_fixed',
                'pass_missing_or_stale_odds',
                'downgrade_low_confidence_sides',
                'downgrade_high_rotation_injury_uncertainty',
                'downgrade_back_to_back_fatigue_mismatches',
                'separate_calculated_edge_from_bet_classification',
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
        $moneyline = 0;
        $spreads = 0;
        $totals = 0;
        $stale = 0;
        $missing = [];

        foreach ($predictions as $prediction) {
            $game = $prediction->game;
            $oddsData = $game?->odds_data;
            $hasMoneyline = $this->extractMarket($oddsData, 'h2h') !== null;
            $hasSpread = $this->extractMarket($oddsData, 'spreads') !== null || $prediction->vegas_spread !== null;
            $hasTotal = $this->extractMarket($oddsData, 'totals') !== null || is_numeric(data_get($prediction->model_metadata, 'market_context.market_total'));

            $moneyline += $hasMoneyline ? 1 : 0;
            $spreads += $hasSpread ? 1 : 0;
            $totals += $hasTotal ? 1 : 0;

            $updatedAt = $game?->odds_updated_at ?? null;
            if ($updatedAt && Carbon::parse($updatedAt)->lt(now()->subHours((int) config('nba.signals.odds_stale_hours', 12)))) {
                $stale++;
            }

            if (! $hasMoneyline || ! $hasSpread || ! $hasTotal) {
                $missing[] = [
                    'game_id' => (int) ($game?->id ?? 0),
                    'matchup' => (string) ($game?->short_name ?: $game?->name ?: ''),
                    'missing' => array_values(array_filter([
                        ! $hasMoneyline ? 'moneyline' : null,
                        ! $hasSpread ? 'spread' : null,
                        ! $hasTotal ? 'total' : null,
                    ])),
                ];
            }
        }

        $coverage = fn (int $count): float => $total > 0 ? round($count / $total * 100, 1) : 0.0;
        $status = match (true) {
            $total === 0 => 'no_slate',
            $coverage($spreads) < 80.0 => 'unhealthy',
            $coverage($moneyline) < 80.0 || $coverage($totals) < 80.0 || $stale > 0 => 'degraded',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'slate_games' => $total,
            'moneyline_coverage' => $coverage($moneyline),
            'spread_coverage' => $coverage($spreads),
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
            ->where('status', '!=', 'STATUS_FINAL')
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
            ->whereHas('game', function ($query) use ($season, $slateDate): void {
                $query->where('season', $season)
                    ->whereDate('game_date', $slateDate->toDateString())
                    ->where('status', '!=', 'STATUS_FINAL');
            })
            ->get()
            ->filter(fn (Prediction $prediction): bool => $prediction->game !== null)
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function finalsSignals(int $season): array
    {
        $rows = PlayoffForecast::query()
            ->with('team')
            ->where('season', $season)
            ->orderByDesc('champion_probability')
            ->orderByDesc('nba_finals_probability')
            ->get()
            ->map(fn (PlayoffForecast $forecast): array => [
                'type' => 'nba_finals',
                'team_id' => (int) $forecast->team_id,
                'team_name' => $this->teamName($forecast->team),
                'conference' => $forecast->conference,
                'projected_seed' => (int) ($forecast->projected_seed ?? 0),
                'playoff_make_probability' => (float) $forecast->playoff_make_probability,
                'finals_probability' => (float) $forecast->nba_finals_probability,
                'champion_probability' => (float) $forecast->champion_probability,
                'selection_score' => (float) $forecast->selection_score,
            ])
            ->all();

        $marketOddsByTeam = $this->futuresOddsLookup->byTeamForSeason('nba', $season);
        $rows = array_map(function (array $row) use ($marketOddsByTeam): array {
            $row['market_odds'] = $marketOddsByTeam[(int) ($row['team_id'] ?? 0)] ?? null;

            return $row;
        }, $rows);

        $rows = $this->futuresEdgeService->annotate($rows, 'champion_probability');

        return array_map(function (array $row): array {
            $row['signal'] = $this->finalsSignalLabel($row);
            $row['reason_codes'] = $this->finalsReasonCodes($row);

            return $row;
        }, array_slice($rows, 0, 10));
    }

    /**
     * @param  array<int,Prediction>  $predictions
     * @return array<int,array<string,mixed>>
     */
    protected function spreadSignals(array $predictions): array
    {
        return array_slice($this->marketRows($predictions, 'spread'), 0, 10);
    }

    /**
     * @param  array<int,Prediction>  $predictions
     * @return array<int,array<string,mixed>>
     */
    protected function moneylineSignals(array $predictions): array
    {
        return array_slice($this->marketRows($predictions, 'moneyline'), 0, 10);
    }

    /**
     * @param  array<int,Prediction>  $predictions
     * @return array<int,array<string,mixed>>
     */
    protected function totalSignals(array $predictions): array
    {
        return array_slice($this->marketRows($predictions, 'total'), 0, 10);
    }

    /**
     * @param  array<int,Prediction>  $predictions
     * @return array<int,array<string,mixed>>
     */
    protected function recommendedBets(array $predictions): array
    {
        $rows = array_values(array_filter(
            $this->betCandidates($predictions),
            fn (array $row): bool => ! str_starts_with((string) ($row['classification'] ?? ''), 'pass')
        ));

        usort($rows, fn (array $left, array $right): int => ((float) ($right['score'] ?? 0) <=> (float) ($left['score'] ?? 0))
            ?: ((float) ($right['edge_points'] ?? $right['probability_edge'] ?? 0) <=> (float) ($left['edge_points'] ?? $left['probability_edge'] ?? 0)));

        return array_slice($rows, 0, 10);
    }

    /**
     * @param  array<int,Prediction>  $predictions
     * @return array<string,mixed>
     */
    protected function passSummary(array $predictions): array
    {
        $candidates = $this->betCandidates($predictions);
        $passes = array_values(array_filter(
            $candidates,
            fn (array $row): bool => str_starts_with((string) ($row['classification'] ?? ''), 'pass')
        ));
        $reasonCounts = [];

        foreach ($passes as $row) {
            $reason = (string) ($row['no_bet_reason'] ?? 'unknown_pass_reason');
            $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
        }

        arsort($reasonCounts);

        return [
            'candidates' => count($candidates),
            'passes' => count($passes),
            'pass_rate' => count($candidates) > 0 ? round(count($passes) / count($candidates) * 100, 1) : 0.0,
            'top_reasons' => array_map(
                fn (string $reason, int $count): array => ['reason' => $reason, 'count' => $count],
                array_keys(array_slice($reasonCounts, 0, 6, true)),
                array_values(array_slice($reasonCounts, 0, 6, true))
            ),
            'sample' => array_slice($passes, 0, 8),
        ];
    }

    /**
     * @param  array<int,Prediction>  $predictions
     * @return array<int,array<string,mixed>>
     */
    protected function betCandidates(array $predictions): array
    {
        $rows = [];

        foreach ($predictions as $prediction) {
            foreach (['spread', 'moneyline', 'total'] as $market) {
                $row = $this->candidateForMarket($prediction, $market);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    protected function candidateForMarket(Prediction $prediction, string $market): ?array
    {
        $game = $prediction->game;
        if (! $game) {
            return null;
        }

        $base = $this->baseGameRow($prediction, $market);
        $reasonCodes = $this->reasonCodes($prediction, $market);
        $riskFlags = $this->riskFlags($prediction, $market);
        $score = $this->candidateScore($prediction, $market, $reasonCodes, $riskFlags);
        $classification = $this->classification($market, $score, $reasonCodes, $riskFlags);
        $noBetReason = str_starts_with($classification, 'pass')
            ? $this->noBetReason($market, $score, $reasonCodes, $riskFlags)
            : null;

        return [
            ...$base,
            'score' => $score,
            'classification' => $classification,
            'no_bet_reason' => $noBetReason,
            'reason_codes' => $reasonCodes,
            'risk_flags' => $riskFlags,
        ];
    }

    /**
     * @param  array<int,Prediction>  $predictions
     * @return array<int,array<string,mixed>>
     */
    protected function marketRows(array $predictions, string $market): array
    {
        $rows = array_values(array_filter(array_map(
            fn (Prediction $prediction): ?array => $this->candidateForMarket($prediction, $market),
            $predictions
        )));

        usort($rows, fn (array $left, array $right): int => ((float) ($right['score'] ?? 0) <=> (float) ($left['score'] ?? 0)));

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    protected function baseGameRow(Prediction $prediction, string $market): array
    {
        $game = $prediction->game;
        $homeWinProbability = (float) $prediction->win_probability;
        $pickSide = $homeWinProbability >= 0.5 ? 'home' : 'away';
        $pickTeam = $pickSide === 'home' ? $game?->homeTeam : $game?->awayTeam;
        $spreadEdge = $this->spreadEdge($prediction);
        $totalEdge = $this->totalEdge($prediction);
        $moneyline = $this->moneylineEdge($prediction);

        return [
            'type' => $market,
            'game_id' => (int) ($game?->id ?? 0),
            'game_date' => $game?->game_date?->toDateString(),
            'matchup' => (string) ($game?->short_name ?: $game?->name ?: ''),
            'pick_side' => $market === 'total' ? $this->totalPickSide($prediction) : $pickSide,
            'team_id' => $market === 'total' ? null : (int) ($pickTeam?->id ?? 0),
            'team_name' => $market === 'total' ? null : $this->teamName($pickTeam),
            'predicted_spread' => (float) $prediction->predicted_spread,
            'vegas_spread' => $prediction->vegas_spread !== null ? (float) $prediction->vegas_spread : null,
            'predicted_total' => (float) $prediction->predicted_total,
            'market_total' => $this->marketTotal($prediction),
            'win_probability' => $homeWinProbability,
            'confidence_score' => (float) $prediction->confidence_score,
            'edge_points' => $market === 'spread' ? $spreadEdge : ($market === 'total' ? $totalEdge : null),
            'probability_edge' => $market === 'moneyline' ? ($moneyline['edge'] ?? null) : null,
            'market_price' => $market === 'moneyline' ? ($moneyline['price'] ?? null) : null,
            'market_implied_probability' => $market === 'moneyline' ? ($moneyline['implied_probability'] ?? null) : null,
        ];
    }

    /**
     * @return list<string>
     */
    protected function reasonCodes(Prediction $prediction, string $market): array
    {
        $codes = [];
        $confidence = (float) $prediction->confidence_score;
        $spreadEdge = $this->spreadEdge($prediction);
        $totalEdge = $this->totalEdge($prediction);
        $moneyline = $this->moneylineEdge($prediction);

        if ($confidence >= (float) config('nba.signals.bet_filter.strong_confidence', 70)) {
            $codes[] = 'strong_model_confidence';
        } elseif ($confidence >= (float) config('nba.signals.bet_filter.min_confidence', 58)) {
            $codes[] = 'usable_model_confidence';
        }

        if ($market === 'spread' && $spreadEdge !== null) {
            $codes[] = abs($spreadEdge) >= (float) config('nba.signals.bet_filter.strong_spread_edge', 4.0)
                ? 'strong_spread_edge'
                : 'spread_edge_present';
        }

        if ($market === 'moneyline' && ($moneyline['edge'] ?? null) !== null) {
            $codes[] = (float) $moneyline['edge'] >= (float) config('nba.signals.bet_filter.min_moneyline_edge', 0.04)
                ? 'moneyline_price_edge'
                : 'moneyline_price_available';
        }

        if ($market === 'total' && $totalEdge !== null) {
            $codes[] = abs($totalEdge) >= (float) config('nba.signals.bet_filter.min_total_edge', 6.0)
                ? 'total_edge_present'
                : 'weak_total_edge';
        }

        if (abs((float) ($prediction->home_away_split_adj ?? 0.0)) >= 1.0) {
            $codes[] = 'home_away_split_signal';
        }

        if (abs((float) ($prediction->turnover_diff_adj ?? 0.0)) >= 1.0) {
            $codes[] = 'turnover_edge_signal';
        }

        if (abs((float) ($prediction->rebound_margin_adj ?? 0.0)) >= 1.0) {
            $codes[] = 'rebounding_edge_signal';
        }

        if (abs((float) ($prediction->injury_spread_adj ?? 0.0)) >= 1.0) {
            $codes[] = 'injury_context_signal';
        }

        $restHome = $prediction->rest_days_home;
        $restAway = $prediction->rest_days_away;
        if ($restHome !== null && $restAway !== null && abs((int) $restHome - (int) $restAway) >= 2) {
            $codes[] = 'rest_advantage_signal';
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return list<string>
     */
    protected function riskFlags(Prediction $prediction, string $market): array
    {
        $flags = [];
        $game = $prediction->game;

        if ($game?->odds_updated_at && Carbon::parse($game->odds_updated_at)->lt(now()->subHours((int) config('nba.signals.odds_stale_hours', 12)))) {
            $flags[] = 'stale_odds';
        }

        if ($market === 'spread' && $this->spreadEdge($prediction) === null) {
            $flags[] = 'spread_market_missing';
        }

        if ($market === 'moneyline' && ($this->moneylineEdge($prediction)['edge'] ?? null) === null) {
            $flags[] = 'moneyline_price_missing';
        }

        if ($market === 'total') {
            if ($this->totalEdge($prediction) === null) {
                $flags[] = 'total_market_missing';
            }
            $flags[] = 'total_model_low_bias_watch';
            if (! (bool) config('nba.signals.bet_filter.total_enabled', false)) {
                $flags[] = 'total_market_disabled';
            }
        }

        if ((float) ($prediction->confidence_score ?? 0.0) < (float) config('nba.signals.bet_filter.min_confidence', 58)) {
            $flags[] = 'low_confidence_bucket';
        }

        if (((int) ($prediction->home_injuries_questionable ?? 0) + (int) ($prediction->away_injuries_questionable ?? 0)) >= 3) {
            $flags[] = 'rotation_injury_uncertainty';
        }

        $restHome = $prediction->rest_days_home;
        $restAway = $prediction->rest_days_away;
        if (($restHome !== null && (int) $restHome <= 1) || ($restAway !== null && (int) $restAway <= 1)) {
            $flags[] = 'back_to_back_or_low_rest';
        }

        return array_values(array_unique($flags));
    }

    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $riskFlags
     */
    protected function candidateScore(Prediction $prediction, string $market, array $reasonCodes, array $riskFlags): float
    {
        $score = 20.0;
        $confidence = (float) $prediction->confidence_score;
        $score += max(0.0, ($confidence - 50.0) * 1.2);

        if ($market === 'spread' && $this->spreadEdge($prediction) !== null) {
            $score += min(28.0, abs((float) $this->spreadEdge($prediction)) * 5.5);
        }

        if ($market === 'moneyline') {
            $score += min(24.0, max(0.0, (float) ($this->moneylineEdge($prediction)['edge'] ?? 0.0)) * 300.0);
        }

        if ($market === 'total') {
            $score += min(18.0, abs((float) ($this->totalEdge($prediction) ?? 0.0)) * 2.0);
            $score -= 28.0;
        }

        $score += count(array_intersect($reasonCodes, [
            'home_away_split_signal',
            'turnover_edge_signal',
            'rebounding_edge_signal',
            'rest_advantage_signal',
        ])) * 3.0;

        $score -= count($riskFlags) * 6.0;

        return round(max(0.0, min(100.0, $score)), 1);
    }

    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $riskFlags
     */
    protected function classification(string $market, float $score, array $reasonCodes, array $riskFlags): string
    {
        if (! (bool) config("nba.signals.bet_filter.{$market}_enabled", $market !== 'total')) {
            return 'pass_market_disabled';
        }

        if ($riskFlags !== []) {
            foreach (['spread_market_missing', 'moneyline_price_missing', 'total_market_missing', 'stale_odds', 'total_model_low_bias_watch'] as $flag) {
                if (in_array($flag, $riskFlags, true)) {
                    return 'pass_risk_control';
                }
            }
        }

        if ($score >= (float) config('nba.signals.bet_filter.strong_min_score', 72)) {
            return 'bet_strong';
        }

        if ($score >= (float) config('nba.signals.bet_filter.lean_min_score', 58)) {
            return 'bet_lean';
        }

        return 'pass_score';
    }

    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $riskFlags
     */
    protected function noBetReason(string $market, float $score, array $reasonCodes, array $riskFlags): string
    {
        foreach ([
            'spread_market_missing',
            'moneyline_price_missing',
            'total_market_missing',
            'stale_odds',
            'total_market_disabled',
            'total_model_low_bias_watch',
            'low_confidence_bucket',
            'rotation_injury_uncertainty',
            'back_to_back_or_low_rest',
        ] as $flag) {
            if (in_array($flag, $riskFlags, true)) {
                return $flag;
            }
        }

        return $score < (float) config('nba.signals.bet_filter.lean_min_score', 58)
            ? 'score_below_threshold'
            : 'market_not_promoted';
    }

    protected function spreadEdge(Prediction $prediction): ?float
    {
        if (! is_numeric($prediction->vegas_spread)) {
            return null;
        }

        $marketSpreadModelConvention = -((float) $prediction->vegas_spread);

        return round((float) $prediction->predicted_spread - $marketSpreadModelConvention, 2);
    }

    protected function totalEdge(Prediction $prediction): ?float
    {
        $marketTotal = $this->marketTotal($prediction);
        if ($marketTotal === null) {
            return null;
        }

        return round((float) $prediction->predicted_total - $marketTotal, 2);
    }

    protected function marketTotal(Prediction $prediction): ?float
    {
        $metadataTotal = data_get($prediction->model_metadata, 'market_context.market_total');
        if (is_numeric($metadataTotal)) {
            return (float) $metadataTotal;
        }

        $market = $this->extractMarket($prediction->game?->odds_data, 'totals');
        foreach (($market['outcomes'] ?? []) as $outcome) {
            if (($outcome['name'] ?? null) === 'Over' && is_numeric($outcome['point'] ?? null)) {
                return (float) $outcome['point'];
            }
        }

        return null;
    }

    /**
     * @return array{edge:?float,price:?float,implied_probability:?float}
     */
    protected function moneylineEdge(Prediction $prediction): array
    {
        $market = $this->extractMarket($prediction->game?->odds_data, 'h2h');
        if ($market === null) {
            return ['edge' => null, 'price' => null, 'implied_probability' => null];
        }

        $homePrice = null;
        $awayPrice = null;
        foreach (($market['outcomes'] ?? []) as $outcome) {
            $name = (string) ($outcome['name'] ?? '');
            if ($this->teamMatches($prediction->game?->homeTeam, $name)) {
                $homePrice = $outcome['price'] ?? null;
            } elseif ($this->teamMatches($prediction->game?->awayTeam, $name)) {
                $awayPrice = $outcome['price'] ?? null;
            }
        }

        $homeProbability = (float) $prediction->win_probability;
        $betHome = $homeProbability >= 0.5;
        $price = $betHome ? $homePrice : $awayPrice;
        if (! is_numeric($price)) {
            return ['edge' => null, 'price' => null, 'implied_probability' => null];
        }

        $modelProbability = $betHome ? $homeProbability : 1 - $homeProbability;
        $implied = $this->americanToImplied((float) $price);

        return [
            'edge' => round($modelProbability - $implied, 4),
            'price' => (float) $price,
            'implied_probability' => round($implied, 4),
        ];
    }

    protected function totalPickSide(Prediction $prediction): string
    {
        $edge = $this->totalEdge($prediction);

        return $edge !== null && $edge > 0 ? 'over' : 'under';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function streakSignals(int $season, CarbonInterface $asOfDate): array
    {
        $rows = [];
        $minLength = 3;
        $teams = Team::query()->orderBy('abbreviation')->get();

        foreach ($teams as $team) {
            $games = Game::query()
                ->where('season', $season)
                ->where('status', 'STATUS_FINAL')
                ->whereDate('game_date', '<=', Carbon::parse($asOfDate)->toDateString())
                ->where(function ($query) use ($team): void {
                    $query->where('home_team_id', $team->id)
                        ->orWhere('away_team_id', $team->id);
                })
                ->orderByDesc('game_date')
                ->limit(8)
                ->get();

            if ($games->count() < $minLength) {
                continue;
            }

            $wins = 0;
            foreach ($games as $game) {
                $won = ((int) $game->home_team_id === (int) $team->id && (int) $game->home_score > (int) $game->away_score)
                    || ((int) $game->away_team_id === (int) $team->id && (int) $game->away_score > (int) $game->home_score);
                if (! $won) {
                    break;
                }
                $wins++;
            }

            if ($wins >= $minLength) {
                $rows[] = [
                    'type' => 'streak',
                    'team_id' => (int) $team->id,
                    'team_name' => $this->teamName($team),
                    'streak' => 'win_streak',
                    'length' => $wins,
                    'label' => 'win streak to monitor',
                    'reason_codes' => ['current_form_watch'],
                ];
            }
        }

        usort($rows, fn (array $left, array $right): int => (int) $right['length'] <=> (int) $left['length']);

        return array_slice($rows, 0, 10);
    }

    protected function finalsSignalLabel(array $row): string
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
    protected function finalsReasonCodes(array $row): array
    {
        $codes = ['playoff_forecast_signal'];
        if ((float) ($row['champion_probability'] ?? 0.0) >= 0.10) {
            $codes[] = 'championship_probability_signal';
        }
        if ((float) data_get($row, 'market_edge.edge_percent_points', 0.0) >= 2.0) {
            $codes[] = 'futures_market_edge';
        }

        return $codes;
    }

    protected function extractMarket(mixed $oddsData, string $marketKey): ?array
    {
        if (! is_array($oddsData)) {
            return null;
        }

        foreach (($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) === $marketKey) {
                    return $market;
                }
            }
        }

        return null;
    }

    protected function americanToImplied(float $odds): float
    {
        if ($odds > 0) {
            return 100 / ($odds + 100);
        }

        return abs($odds) / (abs($odds) + 100);
    }

    protected function teamMatches(?Team $team, string $outcomeName): bool
    {
        if (! $team) {
            return false;
        }

        $name = strtolower($outcomeName);
        $location = strtolower((string) ($team->location ?? ''));
        $mascot = strtolower((string) ($team->name ?? ''));
        $abbr = strtolower((string) ($team->abbreviation ?? ''));
        $fullName = trim("{$location} {$mascot}");

        return ($location !== '' && str_contains($name, $location))
            || ($mascot !== '' && str_contains($name, $mascot))
            || ($abbr !== '' && $name === $abbr)
            || ($fullName !== '' && $name === $fullName);
    }

    protected function teamName(?Team $team): string
    {
        if (! $team) {
            return 'Unknown';
        }

        $full = trim((string) ($team->location ?? '').' '.(string) ($team->name ?? ''));

        return $full !== '' ? $full : (string) ($team->abbreviation ?? 'Unknown');
    }
}
