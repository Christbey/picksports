<?php

namespace App\Services\NBA;

use App\Actions\NBA\CalculateBettingValue;
use App\Models\GameOddsSnapshot;
use App\Models\NBA\Game;
use App\Models\NBA\Play;
use App\Models\NBA\Prediction;
use Illuminate\Support\Collection;

class NbaGameContextLayerService
{
    /**
     * @return array<string, mixed>
     */
    public function analyze(Game $game, ?Prediction $prediction = null, ?array $bestBet = null, bool $includeHistoricalReference = true): array
    {
        $game->loadMissing(['homeTeam', 'awayTeam']);
        $prediction ??= $game->prediction;

        $priorGames = $this->priorMatchupGames($game);
        $marketTotal = $this->marketTotal($game->odds_data)
            ?? $this->latestSnapshotTotal($game)
            ?? $this->numeric(data_get($prediction?->model_metadata, 'market_context.market_total'));

        $seriesTotalTrend = $this->seriesTotalTrend($priorGames, $marketTotal);
        $overtimeAdjusted = $this->overtimeAdjustedTotal($priorGames, $marketTotal);
        $nonOtAverage = $this->nonOvertimeSeriesAverage($priorGames, $marketTotal);
        $quarterSpikes = $this->quarterScoringSpikes($priorGames);
        $foulRisk = $this->lateGameFoulRisk($priorGames);
        $injuryImpact = $this->injuryImpact($prediction);
        $marketMovement = $this->marketMovement($game);
        $modelConflict = $this->modelVsSeriesConflict($prediction, $bestBet, $marketTotal, $seriesTotalTrend, $overtimeAdjusted, $nonOtAverage, $quarterSpikes);
        $betContext = $this->bettingContext($bestBet, $modelConflict, $seriesTotalTrend, $overtimeAdjusted, $nonOtAverage, $quarterSpikes, $foulRisk, $injuryImpact, $marketMovement);

        $riskFlags = array_values(array_unique(array_filter([
            ...$modelConflict['risk_flags'],
            ...$foulRisk['risk_flags'],
            ...$injuryImpact['risk_flags'],
            ...$marketMovement['risk_flags'],
        ])));

        $reasonCodes = array_values(array_unique(array_filter([
            ...$seriesTotalTrend['reason_codes'],
            ...$overtimeAdjusted['reason_codes'],
            ...$nonOtAverage['reason_codes'],
            ...$quarterSpikes['reason_codes'],
            ...$foulRisk['reason_codes'],
            ...$injuryImpact['reason_codes'],
            ...$marketMovement['reason_codes'],
            ...$modelConflict['reason_codes'],
        ])));

        $baseContext = [
            'series_total_trend' => $seriesTotalTrend,
            'overtime_adjusted_total' => $overtimeAdjusted,
            'non_ot_series_average' => $nonOtAverage,
            'quarter_scoring_spikes' => $quarterSpikes,
            'playoff_foul_late_game_risk' => $foulRisk,
            'injury_impact' => $injuryImpact,
            'market_movement' => $marketMovement,
            'model_vs_series_conflict' => $modelConflict,
            'betting_context' => $betContext,
            'risk_flags' => $riskFlags,
            'reason_codes' => $reasonCodes,
        ];

        if ($includeHistoricalReference) {
            $baseContext['historical_spot_reference'] = $this->historicalSpotReference($game, $prediction, $bestBet, $baseContext);
        }

        return $baseContext;
    }

    /**
     * @return Collection<int, Game>
     */
    private function priorMatchupGames(Game $game): Collection
    {
        if (! $game->home_team_id || ! $game->away_team_id) {
            return collect();
        }

        return Game::query()
            ->where('id', '!=', $game->id)
            ->where('status', 'STATUS_FINAL')
            ->where(function ($query) use ($game): void {
                $query->where(function ($q) use ($game): void {
                    $q->where('home_team_id', $game->home_team_id)
                        ->where('away_team_id', $game->away_team_id);
                })->orWhere(function ($q) use ($game): void {
                    $q->where('home_team_id', $game->away_team_id)
                        ->where('away_team_id', $game->home_team_id);
                });
            })
            ->when($game->season, fn ($query) => $query->where('season', $game->season))
            ->when($game->game_date, fn ($query) => $query->where('game_date', '<', $game->game_date))
            ->orderByDesc('game_date')
            ->limit(7)
            ->get();
    }

    /**
     * @param  Collection<int, Game>  $games
     * @return array<string, mixed>
     */
    private function seriesTotalTrend(Collection $games, ?float $marketTotal): array
    {
        $totals = $games->map(fn (Game $game) => $this->gameTotal($game))->filter(fn ($value) => $value !== null)->values();
        $average = $this->average($totals);
        $overMarket = $marketTotal !== null
            ? $totals->filter(fn ($total) => (float) $total > $marketTotal)->count()
            : null;

        $direction = 'unknown';
        if ($marketTotal !== null && $average !== null) {
            $direction = $average >= $marketTotal + 2.0 ? 'over' : ($average <= $marketTotal - 2.0 ? 'under' : 'neutral');
        }

        return [
            'sample_size' => $totals->count(),
            'market_total' => $marketTotal,
            'average_total' => $average,
            'over_market_count' => $overMarket,
            'direction' => $direction,
            'reason_codes' => $direction === 'over' ? ['series_total_trend_over'] : ($direction === 'under' ? ['series_total_trend_under'] : []),
        ];
    }

    /**
     * @param  Collection<int, Game>  $games
     * @return array<string, mixed>
     */
    private function overtimeAdjustedTotal(Collection $games, ?float $marketTotal): array
    {
        $adjusted = $games
            ->map(fn (Game $game) => $this->regulationTotal($game) ?? $this->gameTotal($game))
            ->filter(fn ($value) => $value !== null)
            ->values();
        $overtimeGames = $games->filter(fn (Game $game) => (int) ($game->period ?? 0) > 4)->count();
        $average = $this->average($adjusted);
        $direction = 'unknown';

        if ($marketTotal !== null && $average !== null) {
            $direction = $average >= $marketTotal + 2.0 ? 'over' : ($average <= $marketTotal - 2.0 ? 'under' : 'neutral');
        }

        return [
            'sample_size' => $adjusted->count(),
            'overtime_games' => $overtimeGames,
            'market_total' => $marketTotal,
            'average' => $average,
            'direction' => $direction,
            'reason_codes' => array_values(array_filter([
                $overtimeGames > 0 ? 'overtime_adjusted_total' : null,
                $direction === 'over' ? 'overtime_adjusted_still_over' : null,
                $direction === 'under' ? 'overtime_adjusted_under' : null,
            ])),
        ];
    }

    /**
     * @param  Collection<int, Game>  $games
     * @return array<string, mixed>
     */
    private function nonOvertimeSeriesAverage(Collection $games, ?float $marketTotal): array
    {
        $nonOtTotals = $games
            ->filter(fn (Game $game) => (int) ($game->period ?? 0) <= 4)
            ->map(fn (Game $game) => $this->gameTotal($game))
            ->filter(fn ($value) => $value !== null)
            ->values();
        $average = $this->average($nonOtTotals);
        $direction = 'unknown';

        if ($marketTotal !== null && $average !== null) {
            $direction = $average >= $marketTotal + 2.0 ? 'over' : ($average <= $marketTotal - 2.0 ? 'under' : 'neutral');
        }

        return [
            'sample_size' => $nonOtTotals->count(),
            'market_total' => $marketTotal,
            'average' => $average,
            'direction' => $direction,
            'reason_codes' => $direction === 'over' ? ['non_ot_series_average_over'] : ($direction === 'under' ? ['non_ot_series_average_under'] : []),
        ];
    }

    /**
     * @param  Collection<int, Game>  $games
     * @return array<string, mixed>
     */
    private function quarterScoringSpikes(Collection $games): array
    {
        $spikes = [];
        $maxQuarterTotal = null;

        foreach ($games as $game) {
            foreach ($this->quarterTotals($game) as $quarter => $total) {
                $maxQuarterTotal = $maxQuarterTotal === null ? $total : max($maxQuarterTotal, $total);
                if ($total >= 65) {
                    $spikes[] = [
                        'game_id' => $game->id,
                        'period' => $quarter,
                        'total' => $total,
                    ];
                }
            }
        }

        return [
            'threshold' => 65,
            'count' => count($spikes),
            'max_quarter_total' => $maxQuarterTotal,
            'spikes' => array_slice($spikes, 0, 5),
            'reason_codes' => count($spikes) > 0 ? ['quarter_scoring_spikes'] : [],
        ];
    }

    /**
     * @param  Collection<int, Game>  $games
     * @return array<string, mixed>
     */
    private function lateGameFoulRisk(Collection $games): array
    {
        $gameIds = $games->pluck('id')->all();
        $fourthQuarterFouls = $gameIds === []
            ? 0
            : Play::query()->whereIn('game_id', $gameIds)->where('is_foul', true)->where('period', '>=', 4)->count();
        $closeGames = $games->filter(function (Game $game): bool {
            if (! is_numeric($game->home_score) || ! is_numeric($game->away_score)) {
                return false;
            }

            return abs((int) $game->home_score - (int) $game->away_score) <= 6;
        })->count();
        $overtimeGames = $games->filter(fn (Game $game) => (int) ($game->period ?? 0) > 4)->count();
        $risk = match (true) {
            $overtimeGames > 0 || $closeGames >= 2 || $fourthQuarterFouls >= 18 => 'elevated',
            $closeGames === 1 || $fourthQuarterFouls >= 8 => 'moderate',
            default => 'low',
        };

        return [
            'risk' => $risk,
            'fourth_quarter_plus_fouls' => $fourthQuarterFouls,
            'close_games' => $closeGames,
            'overtime_games' => $overtimeGames,
            'reason_codes' => $risk !== 'low' ? ['playoff_late_game_foul_risk'] : [],
            'risk_flags' => $risk === 'elevated' ? ['late_game_foul_variance'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function injuryImpact(?Prediction $prediction): array
    {
        $metadata = is_array($prediction?->model_metadata ?? null) ? $prediction->model_metadata : [];
        $injuries = is_array($metadata['depth_chart_injuries'] ?? null) ? $metadata['depth_chart_injuries'] : [];
        $homeWeighted = (float) ($injuries['home_out_weighted'] ?? 0.0) + ((float) ($injuries['home_questionable_weighted'] ?? 0.0) * 0.5);
        $awayWeighted = (float) ($injuries['away_out_weighted'] ?? 0.0) + ((float) ($injuries['away_questionable_weighted'] ?? 0.0) * 0.5);
        $totalWeighted = $homeWeighted + $awayWeighted;
        $level = match (true) {
            $totalWeighted >= 4.0 => 'high',
            $totalWeighted >= 1.5 => 'moderate',
            $totalWeighted > 0.0 => 'low',
            default => 'none',
        };

        return [
            'level' => $level,
            'home_weighted_absences' => round($homeWeighted, 2),
            'away_weighted_absences' => round($awayWeighted, 2),
            'spread_adjustment' => $this->numeric($injuries['spread_adjustment'] ?? $prediction?->injury_spread_adj),
            'total_adjustment' => $this->numeric($injuries['total_adjustment'] ?? $prediction?->injury_total_adj),
            'reason_codes' => $level !== 'none' ? ['injury_impact_by_player_importance'] : [],
            'risk_flags' => in_array($level, ['moderate', 'high'], true) ? ['injury_importance_variance'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function marketMovement(Game $game): array
    {
        $snapshots = GameOddsSnapshot::query()
            ->where('sport', 'nba')
            ->where('game_table', 'nba_games')
            ->where('game_id', $game->id)
            ->orderBy('captured_at')
            ->get();

        $first = $snapshots->first();
        $last = $snapshots->last();
        $openTotal = $first ? $this->marketTotal($first->odds_data) : null;
        $currentTotal = $last ? $this->marketTotal($last->odds_data) : $this->marketTotal($game->odds_data);
        $openSpread = $first ? $this->homeSpread($first->odds_data, $game) : null;
        $currentSpread = $last ? $this->homeSpread($last->odds_data, $game) : $this->homeSpread($game->odds_data, $game);
        $totalMove = $openTotal !== null && $currentTotal !== null ? round($currentTotal - $openTotal, 2) : null;
        $spreadMove = $openSpread !== null && $currentSpread !== null ? round($currentSpread - $openSpread, 2) : null;

        return [
            'snapshot_count' => $snapshots->count(),
            'open_total' => $openTotal,
            'current_total' => $currentTotal,
            'total_move' => $totalMove,
            'open_home_spread' => $openSpread,
            'current_home_spread' => $currentSpread,
            'home_spread_move' => $spreadMove,
            'reason_codes' => abs((float) ($totalMove ?? 0.0)) >= 2.0 || abs((float) ($spreadMove ?? 0.0)) >= 1.5 ? ['market_movement'] : [],
            'risk_flags' => $snapshots->count() === 0 ? ['market_movement_unavailable'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function modelVsSeriesConflict(?Prediction $prediction, ?array $bestBet, ?float $marketTotal, array $seriesTrend, array $overtimeAdjusted, array $nonOtAverage, array $quarterSpikes): array
    {
        $type = strtolower((string) ($bestBet['type'] ?? ''));
        $recommendation = strtolower((string) ($bestBet['recommendation'] ?? ''));
        $modelTotal = $this->numeric($prediction?->predicted_total);
        $modelTotalDirection = null;
        if ($marketTotal !== null && $modelTotal !== null) {
            $modelTotalDirection = $modelTotal > $marketTotal ? 'over' : ($modelTotal < $marketTotal ? 'under' : 'neutral');
        }

        $seriesDirections = array_filter([
            $seriesTrend['direction'] ?? null,
            $overtimeAdjusted['direction'] ?? null,
            $nonOtAverage['direction'] ?? null,
        ], fn ($direction) => in_array($direction, ['over', 'under'], true));
        $seriesOverVotes = count(array_filter($seriesDirections, fn ($direction) => $direction === 'over'));
        $seriesUnderVotes = count(array_filter($seriesDirections, fn ($direction) => $direction === 'under'));
        $seriesDirection = $seriesOverVotes > $seriesUnderVotes ? 'over' : ($seriesUnderVotes > $seriesOverVotes ? 'under' : 'neutral');
        $betDirection = str_contains($recommendation, 'under') ? 'under' : (str_contains($recommendation, 'over') ? 'over' : $modelTotalDirection);
        $hasConflict = $type === 'total'
            && $betDirection !== null
            && $seriesDirection !== 'neutral'
            && $betDirection !== $seriesDirection;

        if (! $hasConflict && $type === 'total' && $betDirection === 'under' && (int) ($quarterSpikes['count'] ?? 0) >= 2) {
            $hasConflict = true;
            $seriesDirection = 'over';
        }

        return [
            'has_conflict' => $hasConflict,
            'model_total_direction' => $modelTotalDirection,
            'series_direction' => $seriesDirection,
            'bet_direction' => $betDirection,
            'market_total' => $marketTotal,
            'model_total' => $modelTotal,
            'reason_codes' => $hasConflict ? ['model_vs_series_conflict'] : [],
            'risk_flags' => $hasConflict ? ['model_series_total_conflict'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bettingContext(?array $bestBet, array $modelConflict, array $seriesTrend, array $overtimeAdjusted, array $nonOtAverage, array $quarterSpikes, array $foulRisk, array $injuryImpact, array $marketMovement): array
    {
        $forBet = [];
        $againstBet = [];
        $passReasons = [];
        $classification = 'playable';
        $recommendation = strtolower((string) ($bestBet['recommendation'] ?? ''));
        $betDirection = str_contains($recommendation, 'under') ? 'under' : (str_contains($recommendation, 'over') ? 'over' : null);
        $bestBetType = strtolower((string) ($bestBet['type'] ?? ''));
        $bestBetEdge = is_numeric($bestBet['edge'] ?? null) ? (float) $bestBet['edge'] : null;
        $strongTotalEdge = $bestBetType === 'total' && $bestBetEdge !== null && $bestBetEdge >= 5.0;
        $marketMoveStillPlayable = $this->marketMoveStillPlayable($betDirection, $marketMovement);

        if ($bestBet) {
            $forBet[] = (string) ($bestBet['reasoning'] ?? 'Model edge exists against the current market.');
        }

        if (($modelConflict['has_conflict'] ?? false) === true) {
            $againstBet[] = 'Series total context points the other direction from the model total.';
            if ($strongTotalEdge && $marketMoveStillPlayable) {
                $forBet[] = 'Model total edge is strong enough to keep as a reduced-size play despite series conflict.';
            } else {
                $passReasons[] = 'model_vs_series_conflict';
            }
        }

        if (($seriesTrend['direction'] ?? null) !== 'unknown') {
            $this->bucketDirectionalContext(
                $forBet,
                $againstBet,
                'Series total trend',
                (string) $seriesTrend['direction'],
                $betDirection
            );
        }

        if (($overtimeAdjusted['direction'] ?? null) !== 'unknown') {
            $this->bucketDirectionalContext(
                $forBet,
                $againstBet,
                'Overtime-adjusted total trend',
                (string) $overtimeAdjusted['direction'],
                $betDirection
            );
        }

        if ((int) ($quarterSpikes['count'] ?? 0) >= 2) {
            $againstBet[] = 'Multiple prior matchup quarters cleared the spike threshold.';
        }

        if (($foulRisk['risk'] ?? 'low') === 'elevated') {
            $againstBet[] = 'Close-game and foul profile adds late total variance.';
        }

        if (in_array(($injuryImpact['level'] ?? 'none'), ['moderate', 'high'], true)) {
            $againstBet[] = 'Injury impact is meaningful enough to widen the outcome range.';
        }

        if (abs((float) ($marketMovement['total_move'] ?? 0.0)) >= 2.0 || abs((float) ($marketMovement['home_spread_move'] ?? 0.0)) >= 1.5) {
            $forBet[] = 'Market movement is material and should be compared to the model edge.';
        }

        if ($passReasons !== []) {
            $classification = 'pass_or_wait';
        }

        if (! $bestBet) {
            $classification = 'clear_pass';
            $passReasons[] = 'no_qualified_market_edge';
        } elseif (($modelConflict['has_conflict'] ?? false) === true && $strongTotalEdge && $marketMoveStillPlayable) {
            $classification = 'small_play_context_risk';
        }

        return [
            'classification' => $classification,
            'for_bet' => array_values(array_unique($forBet)),
            'against_bet' => array_values(array_unique($againstBet)),
            'pass_reasons' => array_values(array_unique($passReasons)),
        ];
    }

    /**
     * @param  array<string, mixed>  $marketMovement
     */
    private function marketMoveStillPlayable(?string $betDirection, array $marketMovement): bool
    {
        if (! in_array($betDirection, ['over', 'under'], true)) {
            return true;
        }

        $totalMove = $marketMovement['total_move'] ?? null;
        if (! is_numeric($totalMove)) {
            return true;
        }

        $move = (float) $totalMove;

        return $betDirection === 'under'
            ? $move >= -1.0
            : $move <= 1.0;
    }

    /**
     * @param  array<int, string>  $forBet
     * @param  array<int, string>  $againstBet
     */
    private function bucketDirectionalContext(array &$forBet, array &$againstBet, string $label, string $direction, ?string $betDirection): void
    {
        if (! in_array($direction, ['over', 'under'], true)) {
            return;
        }

        $message = "{$label}: {$direction}";
        if ($betDirection === null || $betDirection === $direction) {
            $forBet[] = $message;

            return;
        }

        $againstBet[] = $message;
    }

    /**
     * @param  array<string, mixed>  $currentContext
     * @return array<string, mixed>
     */
    private function historicalSpotReference(Game $game, ?Prediction $prediction, ?array $bestBet, array $currentContext): array
    {
        if (! $prediction || ! $bestBet) {
            return [
                'available' => false,
                'reason' => 'missing_prediction_or_bet_context',
                'sample_size' => 0,
                'matches' => [],
            ];
        }

        $currentSpot = $this->spotSignature($prediction, $bestBet, $currentContext);
        $candidates = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->where('id', '!=', $prediction->id)
            ->whereHas('game', function ($query) use ($game): void {
                $query->where('status', 'STATUS_FINAL')
                    ->whereNotNull('espn_event_id')
                    ->when($game->game_date, fn ($q) => $q->where('game_date', '<', $game->game_date));
            })
            ->whereNotNull('predicted_spread')
            ->whereNotNull('predicted_total')
            ->orderByDesc('id')
            ->limit(120)
            ->get();

        $matches = [];
        foreach ($candidates as $candidate) {
            $candidateGame = $candidate->game;
            if (! $candidateGame instanceof Game) {
                continue;
            }

            $candidateBestBet = $this->bestBetForGame($candidateGame);
            if (! $candidateBestBet) {
                continue;
            }

            $candidateContext = $this->analyze($candidateGame, $candidate, $candidateBestBet, false);
            $candidateSpot = $this->spotSignature($candidate, $candidateBestBet, $candidateContext);
            $similarity = $this->spotSimilarity($currentSpot, $candidateSpot);
            if ($similarity['score'] < 6) {
                continue;
            }

            $matches[] = [
                'game_id' => (int) $candidateGame->id,
                'date' => $candidateGame->game_date?->toDateString(),
                'matchup' => (string) ($candidateGame->short_name ?: $candidateGame->name ?: ''),
                'score' => $similarity['score'],
                'matched_reason_codes' => $similarity['matched_reason_codes'],
                'matched_risk_flags' => $similarity['matched_risk_flags'],
                'classification' => $candidateSpot['classification'],
                'market' => $candidateSpot['market'],
                'bet_direction' => $candidateSpot['bet_direction'],
                'model_edge' => $candidateSpot['edge'],
                'bet_hit' => $this->historicalBetHit($candidateGame, $candidateBestBet),
                'winner_correct' => $candidate->winner_correct,
                'spread_error' => $this->numeric($candidate->spread_error),
                'total_error' => $this->numeric($candidate->total_error),
                'actual_total' => $this->numeric($candidate->actual_total) ?? $this->gameTotal($candidateGame),
            ];
        }

        usort($matches, fn (array $left, array $right): int => $right['score'] <=> $left['score']);
        $matches = array_slice($matches, 0, 25);
        $betOutcomes = array_values(array_filter(array_map(fn (array $match) => $match['bet_hit'], $matches), fn ($value) => $value !== null));
        $winnerOutcomes = array_values(array_filter(array_map(fn (array $match) => $match['winner_correct'], $matches), fn ($value) => $value !== null));
        $spreadErrors = array_values(array_filter(array_map(fn (array $match) => $match['spread_error'], $matches), fn ($value) => $value !== null));
        $totalErrors = array_values(array_filter(array_map(fn (array $match) => $match['total_error'], $matches), fn ($value) => $value !== null));

        return [
            'available' => count($matches) > 0,
            'sample_size' => count($matches),
            'current_spot' => $currentSpot,
            'hit_rate' => $this->rate($betOutcomes),
            'winner_accuracy' => $this->rate($winnerOutcomes),
            'avg_spread_error' => $this->average(collect($spreadErrors)),
            'avg_total_error' => $this->average(collect($totalErrors)),
            'matches' => array_slice($matches, 0, 8),
            'min_similarity_score' => 6,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function spotSignature(Prediction $prediction, array $bestBet, array $context): array
    {
        $market = strtolower((string) ($bestBet['type'] ?? ''));
        $recommendation = strtolower((string) ($bestBet['recommendation'] ?? ''));
        $direction = str_contains($recommendation, 'under') ? 'under' : (str_contains($recommendation, 'over') ? 'over' : null);
        $edge = is_numeric($bestBet['edge'] ?? null) ? (float) $bestBet['edge'] : null;

        return [
            'market' => $market,
            'bet_direction' => $direction,
            'classification' => (string) data_get($context, 'betting_context.classification', 'unknown'),
            'edge' => $edge,
            'edge_bucket' => $edge === null ? 'unknown' : ($edge >= 6.0 ? 'strong' : ($edge >= 3.0 ? 'medium' : 'thin')),
            'confidence_bucket' => (float) ($prediction->confidence_score ?? 0.0) >= 70.0 ? 'high' : ((float) ($prediction->confidence_score ?? 0.0) >= 58.0 ? 'medium' : 'low'),
            'reason_codes' => array_values(array_map('strval', (array) ($context['reason_codes'] ?? []))),
            'risk_flags' => array_values(array_map('strval', (array) ($context['risk_flags'] ?? []))),
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function spotSimilarity(array $current, array $candidate): array
    {
        $currentCodes = (array) ($current['reason_codes'] ?? []);
        $candidateCodes = (array) ($candidate['reason_codes'] ?? []);
        $currentRisks = (array) ($current['risk_flags'] ?? []);
        $candidateRisks = (array) ($candidate['risk_flags'] ?? []);
        $matchedCodes = array_values(array_intersect($currentCodes, $candidateCodes));
        $matchedRisks = array_values(array_intersect($currentRisks, $candidateRisks));
        $score = 0;
        $score += $current['market'] === $candidate['market'] ? 3 : 0;
        $score += $current['bet_direction'] !== null && $current['bet_direction'] === $candidate['bet_direction'] ? 3 : 0;
        $score += $current['classification'] === $candidate['classification'] ? 2 : 0;
        $score += $current['edge_bucket'] === $candidate['edge_bucket'] ? 2 : 0;
        $score += $current['confidence_bucket'] === $candidate['confidence_bucket'] ? 1 : 0;
        $score += min(8, count($matchedCodes) * 2);
        $score += min(4, count($matchedRisks));

        return [
            'score' => $score,
            'matched_reason_codes' => $matchedCodes,
            'matched_risk_flags' => $matchedRisks,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function bestBetForGame(Game $game): ?array
    {
        $game->loadMissing(['prediction', 'homeTeam', 'awayTeam']);
        $recommendations = app(CalculateBettingValue::class)->execute($game);
        if (! is_array($recommendations) || $recommendations === []) {
            return null;
        }

        $priority = ['moneyline' => 0, 'total' => 1, 'spread' => 2];
        usort($recommendations, function (array $left, array $right) use ($priority): int {
            $leftPriority = $priority[(string) ($left['type'] ?? '')] ?? 99;
            $rightPriority = $priority[(string) ($right['type'] ?? '')] ?? 99;

            return $leftPriority === $rightPriority
                ? ((float) ($right['edge'] ?? 0.0)) <=> ((float) ($left['edge'] ?? 0.0))
                : $leftPriority <=> $rightPriority;
        });

        return $recommendations[0] ?? null;
    }

    private function historicalBetHit(Game $game, array $bestBet): ?bool
    {
        $type = strtolower((string) ($bestBet['type'] ?? ''));
        $recommendation = strtolower((string) ($bestBet['recommendation'] ?? ''));
        if ($type === 'total') {
            $market = $this->numeric($bestBet['market_line'] ?? null);
            $actual = $this->gameTotal($game);
            if ($market === null || $actual === null) {
                return null;
            }

            return str_contains($recommendation, 'under') ? $actual < $market : $actual > $market;
        }

        if ($type === 'spread') {
            $market = $this->numeric($bestBet['market_home_line'] ?? $bestBet['market_line'] ?? null);
            if ($market === null || ! is_numeric($game->home_score) || ! is_numeric($game->away_score)) {
                return null;
            }
            $homeCovered = ((float) $game->home_score + $market) > (float) $game->away_score;
            $betHome = str_contains($recommendation, strtolower((string) $game->homeTeam?->name))
                || str_contains($recommendation, strtolower((string) $game->homeTeam?->location));

            return $betHome ? $homeCovered : ! $homeCovered;
        }

        if ($type === 'moneyline' && is_numeric($game->home_score) && is_numeric($game->away_score)) {
            $homeWon = (float) $game->home_score > (float) $game->away_score;
            $betHome = str_contains($recommendation, strtolower((string) $game->homeTeam?->name))
                || str_contains($recommendation, strtolower((string) $game->homeTeam?->location));

            return $betHome ? $homeWon : ! $homeWon;
        }

        return null;
    }

    private function gameTotal(Game $game): ?float
    {
        if (! is_numeric($game->home_score) || ! is_numeric($game->away_score)) {
            return null;
        }

        return (float) $game->home_score + (float) $game->away_score;
    }

    private function regulationTotal(Game $game): ?float
    {
        $home = $this->lineScoreTotal($game->home_linescores, 4);
        $away = $this->lineScoreTotal($game->away_linescores, 4);

        return $home !== null && $away !== null ? $home + $away : null;
    }

    private function lineScoreTotal(mixed $linescores, int $maxPeriod): ?float
    {
        if (! is_array($linescores)) {
            return null;
        }

        $total = 0.0;
        $found = false;
        foreach ($linescores as $line) {
            if (! is_array($line)) {
                continue;
            }
            $period = (int) ($line['period'] ?? 0);
            $value = $this->numeric($line['value'] ?? null);
            if ($period < 1 || $period > $maxPeriod || $value === null) {
                continue;
            }

            $total += $value;
            $found = true;
        }

        return $found ? $total : null;
    }

    /**
     * @return array<int, float>
     */
    private function quarterTotals(Game $game): array
    {
        $home = $this->linescoresByPeriod($game->home_linescores);
        $away = $this->linescoresByPeriod($game->away_linescores);
        $periods = array_unique([...array_keys($home), ...array_keys($away)]);
        sort($periods);

        $totals = [];
        foreach ($periods as $period) {
            if ($period > 4) {
                continue;
            }

            $totals[$period] = (float) ($home[$period] ?? 0.0) + (float) ($away[$period] ?? 0.0);
        }

        return $totals;
    }

    /**
     * @return array<int, float>
     */
    private function linescoresByPeriod(mixed $linescores): array
    {
        if (! is_array($linescores)) {
            return [];
        }

        $values = [];
        foreach ($linescores as $line) {
            if (! is_array($line)) {
                continue;
            }
            $period = (int) ($line['period'] ?? 0);
            $value = $this->numeric($line['value'] ?? null);
            if ($period < 1 || $value === null) {
                continue;
            }
            $values[$period] = $value;
        }

        return $values;
    }

    private function marketTotal(mixed $oddsData): ?float
    {
        if (! is_array($oddsData)) {
            return null;
        }

        foreach (($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) !== 'totals') {
                    continue;
                }
                foreach (($market['outcomes'] ?? []) as $outcome) {
                    if (($outcome['name'] ?? null) === 'Over' && is_numeric($outcome['point'] ?? null)) {
                        return (float) $outcome['point'];
                    }
                }
            }
        }

        return null;
    }

    private function latestSnapshotTotal(Game $game): ?float
    {
        $snapshot = GameOddsSnapshot::query()
            ->where('sport', 'nba')
            ->where('game_table', 'nba_games')
            ->where('game_id', $game->id)
            ->orderByDesc('captured_at')
            ->first();

        return $snapshot ? $this->marketTotal($snapshot->odds_data) : null;
    }

    private function homeSpread(mixed $oddsData, Game $game): ?float
    {
        if (! is_array($oddsData)) {
            return null;
        }

        $homeNames = array_filter([
            strtolower(trim((string) ($game->homeTeam?->location.' '.$game->homeTeam?->name))),
            strtolower((string) $game->homeTeam?->name),
            strtolower((string) $game->homeTeam?->location),
            strtolower((string) $game->homeTeam?->abbreviation),
        ]);

        foreach (($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) !== 'spreads') {
                    continue;
                }
                foreach (($market['outcomes'] ?? []) as $outcome) {
                    $name = strtolower((string) ($outcome['name'] ?? ''));
                    $point = $this->numeric($outcome['point'] ?? null);
                    if ($point === null) {
                        continue;
                    }

                    foreach ($homeNames as $homeName) {
                        if ($homeName !== '' && str_contains($name, $homeName)) {
                            return $point;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, mixed>  $values
     */
    private function average(Collection $values): ?float
    {
        if ($values->isEmpty()) {
            return null;
        }

        return round((float) $values->avg(), 1);
    }

    /**
     * @param  array<int, bool>  $outcomes
     */
    private function rate(array $outcomes): ?float
    {
        if ($outcomes === []) {
            return null;
        }

        $wins = count(array_filter($outcomes));

        return round($wins / count($outcomes) * 100, 1);
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
