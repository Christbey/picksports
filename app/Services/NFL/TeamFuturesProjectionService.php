<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\TeamMetric;
use App\Models\NFL\TeamMetricSnapshot;
use App\Services\Sports\FuturesOddsLookupService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class TeamFuturesProjectionService
{
    public function __construct(
        protected FuturesOddsLookupService $futuresOddsLookup,
        protected TeamOffseasonSignalService $teamOffseasonSignalService,
        protected RegularSeasonFuturesResults $results
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function supportedMarkets(): array
    {
        return (array) config('nfl.team_futures.markets', []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function projections(
        int $season,
        string $market = 'season_wins',
        CarbonInterface|string|null $asOfDate = null,
        bool $requireHistoricalMetrics = false,
        bool $onlyWithOdds = false,
        string $sortBy = 'projected_total',
        string $direction = 'desc',
        int $limit = 32,
        bool $pointInTime = false
    ): array {
        $marketConfig = $this->supportedMarkets()[$market] ?? null;
        if ($marketConfig === null) {
            return [];
        }

        $targetDate = $asOfDate !== null ? Carbon::parse($asOfDate) : null;
        $metrics = $this->metricsByTeam($season, $targetDate, $requireHistoricalMetrics, $pointInTime);
        if ($metrics === []) {
            return [];
        }

        $oddsByTeam = $targetDate !== null
            ? $this->oddsForMarketAt($season, $market, $targetDate, $pointInTime ? 36 : null)
            : [];
        // The offseason service reads mutable injury and usage data.
        $offseasonSignals = $targetDate !== null && ! $pointInTime
            ? $this->teamOffseasonSignalService->signalsForSeason($season, $targetDate)
            : [];

        $records = $this->results->records($season, $targetDate ?? now());
        $seasonGames = RegularSeasonFuturesResults::gamesPerTeam($season);
        $rows = [];
        foreach ($metrics as $teamId => $metric) {
            $offseasonSignal = $offseasonSignals[$teamId] ?? [];
            $projectionMetric = $metric;
            if (($metric['_source'] ?? null) !== 'snapshot') {
                $projectionMetric['predictive_rating'] = (float) ($projectionMetric['predictive_rating'] ?? 0.0)
                    + (float) ($offseasonSignal['offseason_adjustment'] ?? 0.0);
                $projectionMetric['injury_total_adjustment'] = (float) ($projectionMetric['injury_total_adjustment'] ?? 0.0)
                    + (float) ($offseasonSignal['injury_adjustment'] ?? 0.0);
            }

            $record = $records[$teamId] ?? null;
            $wins = max(0, (int) $metric['wins']);
            $losses = max(0, (int) $metric['losses']);
            $ties = max(0, (int) ($metric['ties'] ?? 0));
            $metricGames = $wins + $losses + $ties;
            if ($record !== null && $record['games'] >= $metricGames) {
                $wins = $record['wins'];
                $losses = $record['losses'];
                $ties = $record['ties'];
            }

            $gamesPlayed = $wins + $losses + $ties;
            $remainingGames = max(0, $seasonGames - $gamesPlayed);

            $projectionMetric['wins'] = $wins;
            $projectedRemainingWinPct = $this->projectedRemainingWinPct($projectionMetric, $gamesPlayed);
            $projectedTotal = round($wins + ($remainingGames * $projectedRemainingWinPct), 3);
            $marketOdds = $oddsByTeam[$teamId] ?? null;
            $line = isset($marketOdds['line']) && is_numeric($marketOdds['line'])
                ? (float) $marketOdds['line']
                : null;
            $stddev = sqrt($remainingGames * $projectedRemainingWinPct * (1 - $projectedRemainingWinPct));
            [$overProbability, $underProbability, $pushProbability] = $line !== null
                ? $this->winTotalProbabilities($wins, $remainingGames, $projectedRemainingWinPct, $line)
                : [null, null, null];

            $rows[] = [
                'team_id' => $teamId,
                'season' => $season,
                'market' => $market,
                'as_of_date' => $targetDate?->toIso8601String(),
                'wins' => $wins,
                'losses' => $losses,
                'ties' => $ties,
                'games_played' => $gamesPlayed,
                'remaining_games' => $remainingGames,
                'projected_remaining_win_pct' => round($projectedRemainingWinPct, 4),
                'projected_total' => $projectedTotal,
                'market_odds' => $marketOdds,
                'over_probability' => $overProbability,
                'under_probability' => $underProbability,
                'push_probability' => $pushProbability,
                'projection_factors' => [
                    'predictive_rating' => round((float) ($projectionMetric['predictive_rating'] ?? 0.0), 3),
                    'future_strength_of_schedule' => round((float) ($projectionMetric['future_strength_of_schedule'] ?? 0.0), 3),
                    'recent_form_rating' => round((float) ($metric['recent_form_rating'] ?? 0.0), 3),
                    'injury_total_adjustment' => round((float) ($projectionMetric['injury_total_adjustment'] ?? 0.0), 3),
                    'offseason_adjustment' => round((float) ($offseasonSignal['offseason_adjustment'] ?? 0.0), 3),
                    'qb_continuity_signal' => round((float) ($offseasonSignal['qb_continuity_signal'] ?? 0.0), 3),
                    'skill_continuity_signal' => round((float) ($offseasonSignal['skill_continuity_signal'] ?? 0.0), 3),
                    'returning_production_share' => round((float) ($offseasonSignal['returning_production_share'] ?? 0.0), 3),
                    'current_win_pct' => $gamesPlayed > 0 ? round($wins / $gamesPlayed, 4) : null,
                    'remaining_win_pct' => round($projectedRemainingWinPct, 4),
                    'wins_stddev' => round($stddev, 4),
                ],
            ];
        }

        if ($onlyWithOdds) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => is_array($row['market_odds'] ?? null)
            ));
        }

        usort($rows, function (array $left, array $right) use ($sortBy, $direction): int {
            $leftValue = data_get($left, $sortBy);
            $rightValue = data_get($right, $sortBy);
            $comparison = ($leftValue <=> $rightValue);

            return strtolower($direction) === 'asc' ? $comparison : -$comparison;
        });

        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function metricsByTeam(
        int $season,
        CarbonInterface|string|null $asOfDate = null,
        bool $requireHistoricalMetrics = false,
        bool $pointInTime = false
    ): array {
        if ($asOfDate !== null) {
            $snapshotRows = TeamMetricSnapshot::query()
                ->where('season', $season)
                ->where('captured_at', '<=', Carbon::parse($asOfDate))
                ->when($pointInTime, fn ($query) => $query->whereDate('calculation_date', '<=', Carbon::parse($asOfDate)))
                ->orderByDesc('captured_at')
                ->orderByDesc('id')
                ->get([
                    'team_id',
                    'wins',
                    'losses',
                    'predictive_rating',
                    'future_strength_of_schedule',
                    'recent_form_rating',
                    'injury_total_adjustment',
                    'calculation_date',
                ]);

            $snapshotByTeam = [];
            foreach ($snapshotRows as $row) {
                $teamId = (int) ($row->team_id ?? 0);
                if ($teamId <= 0 || isset($snapshotByTeam[$teamId])) {
                    continue;
                }

                $snapshotByTeam[$teamId] = [
                    'wins' => (int) ($row->wins ?? 0),
                    'losses' => (int) ($row->losses ?? 0),
                    'predictive_rating' => (float) ($row->predictive_rating ?? 1500.0),
                    'future_strength_of_schedule' => (float) ($row->future_strength_of_schedule ?? 1500.0),
                    'recent_form_rating' => (float) ($row->recent_form_rating ?? 0.0),
                    'injury_total_adjustment' => (float) ($row->injury_total_adjustment ?? 0.0),
                    'calculation_date' => $row->calculation_date?->toDateString(),
                    '_source' => 'snapshot',
                ];
            }

            if ($snapshotByTeam !== []) {
                return $snapshotByTeam;
            }

            if ($requireHistoricalMetrics) {
                return [];
            }
        }

        $query = TeamMetric::query()
            ->where('season', $season)
            ->where('season_type', (string) config('nfl.season.types.regular', 2))
            ->orderByDesc('calculation_date')
            ->orderByDesc('id');

        if ($pointInTime && $asOfDate !== null) {
            $query->where('updated_at', '<=', $asOfDate)
                ->whereDate('calculation_date', '<=', $asOfDate);
        }

        $rows = $query->get([
            'team_id',
            'wins',
            'losses',
            'ties',
            'predictive_rating',
            'future_strength_of_schedule',
            'recent_form_rating',
            'injury_total_adjustment',
            'calculation_date',
        ]);

        $byTeam = [];
        foreach ($rows as $row) {
            $teamId = (int) ($row->team_id ?? 0);
            if ($teamId <= 0 || isset($byTeam[$teamId])) {
                continue;
            }

            $byTeam[$teamId] = [
                'wins' => (int) ($row->wins ?? 0),
                'losses' => (int) ($row->losses ?? 0),
                'predictive_rating' => (float) ($row->predictive_rating ?? 1500.0),
                'future_strength_of_schedule' => (float) ($row->future_strength_of_schedule ?? 1500.0),
                'recent_form_rating' => (float) ($row->recent_form_rating ?? 0.0),
                'injury_total_adjustment' => (float) ($row->injury_total_adjustment ?? 0.0),
                'calculation_date' => $row->calculation_date?->toDateString(),
                'ties' => (int) ($row->ties ?? 0),
                '_source' => 'season_metric',
            ];
        }

        if ($asOfDate !== null && $byTeam !== [] && ! $pointInTime) {
            $records = $this->teamRecordsByDate($season, Carbon::parse($asOfDate));

            foreach ($records as $teamId => $record) {
                if (! isset($byTeam[$teamId])) {
                    continue;
                }

                $byTeam[$teamId]['wins'] = $record['wins'];
                $byTeam[$teamId]['losses'] = $record['losses'];
                $byTeam[$teamId]['ties'] = $record['ties'];
            }
        }

        return $byTeam;
    }

    /**
     * @return array<int, array{wins:int,losses:int,ties:int}>
     */
    protected function teamRecordsByDate(int $season, CarbonInterface $asOfDate): array
    {
        $games = Game::query()
            ->where('season', $season)
            ->where('status', config('nfl.statuses.final', 'STATUS_FINAL'))
            ->where('game_date', '<=', $asOfDate)
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->where(function ($query): void {
                $query->whereNull('season_type')
                    ->orWhere('season_type', config('nfl.season.types.regular', 2));
            })
            ->get([
                'home_team_id',
                'away_team_id',
                'home_score',
                'away_score',
            ]);

        $records = [];

        foreach ($games as $game) {
            $homeTeamId = (int) ($game->home_team_id ?? 0);
            $awayTeamId = (int) ($game->away_team_id ?? 0);
            $homeScore = (int) ($game->home_score ?? 0);
            $awayScore = (int) ($game->away_score ?? 0);

            if ($homeTeamId <= 0 || $awayTeamId <= 0) {
                continue;
            }

            $records[$homeTeamId] ??= ['wins' => 0, 'losses' => 0, 'ties' => 0];
            $records[$awayTeamId] ??= ['wins' => 0, 'losses' => 0, 'ties' => 0];

            if ($homeScore === $awayScore) {
                $records[$homeTeamId]['ties']++;
                $records[$awayTeamId]['ties']++;
            } elseif ($homeScore > $awayScore) {
                $records[$homeTeamId]['wins']++;
                $records[$awayTeamId]['losses']++;
            } else {
                $records[$awayTeamId]['wins']++;
                $records[$homeTeamId]['losses']++;
            }
        }

        return $records;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function oddsForMarketAt(int $season, string $market, CarbonInterface $asOfDate, ?int $maxAgeHours = null): array
    {
        $marketConfig = $this->supportedMarkets()[$market] ?? [];
        $marketKeys = (array) ($marketConfig['odds_market_keys'] ?? []);

        if ($market === 'season_wins') {
            return $this->futuresOddsLookup->nflTeamWinTotalsBySeasonAt($season, $asOfDate, $marketKeys, $maxAgeHours);
        }

        return [];
    }

    protected function projectedRemainingWinPct(array $metric, int $gamesPlayed): float
    {
        $priorWinPct = (float) config('nfl.team_futures.prior_win_pct', 0.5);
        $strengthWeight = (float) config('nfl.team_futures.strength_weight', 0.55);
        $paceWeight = (float) config('nfl.team_futures.pace_weight', 0.30);
        $priorWeight = (float) config('nfl.team_futures.prior_weight', 0.15);
        $leagueAverageElo = max(1.0, (float) config('nfl.elo.default_rating', 1500.0));
        $predictiveSignalScale = max(0.1, (float) config('nfl.team_futures.predictive_signal_scale', 10.0));
        $recentFormSignalScale = max(0.1, (float) config('nfl.team_futures.recent_form_signal_scale', 20.0));
        $sosSignalScale = max(0.1, (float) config('nfl.team_futures.sos_signal_scale', 25.0));

        $paceWinPct = $gamesPlayed > 0
            ? ((float) ($metric['wins'] ?? 0.0) / $gamesPlayed)
            : $priorWinPct;

        $predictiveRating = (float) ($metric['predictive_rating'] ?? 0.0);
        $futureStrengthOfSchedule = isset($metric['future_strength_of_schedule']) && is_numeric($metric['future_strength_of_schedule'])
            ? (float) $metric['future_strength_of_schedule']
            : null;
        $recentFormRating = (float) ($metric['recent_form_rating'] ?? 0.0);

        // Historical team metrics are stored on a centered scale (~ -15 to +15),
        // while older rows may still use Elo-like values around 1500.
        $predictiveSignal = abs($predictiveRating) > 200
            ? (($predictiveRating - $leagueAverageElo) / $sosSignalScale)
            : ($predictiveRating / $predictiveSignalScale);
        $scheduleSignal = $futureStrengthOfSchedule !== null
            ? (($futureStrengthOfSchedule - $leagueAverageElo) / $sosSignalScale)
            : 0.0;
        $recentSignal = $recentFormRating / $recentFormSignalScale;

        $strengthSignal = $predictiveSignal + $recentSignal - $scheduleSignal;

        $strengthWinPct = $this->sigmoid($strengthSignal);

        $blended = ($strengthWeight * $strengthWinPct)
            + ($paceWeight * $paceWinPct)
            + ($priorWeight * $priorWinPct);

        return max(0.01, min(0.99, $blended));
    }

    /**
     * @return array{float, float, float}
     */
    protected function winTotalProbabilities(int $wins, int $remainingGames, float $winPct, float $line): array
    {
        $over = $under = $push = 0.0;
        $probability = (1 - $winPct) ** $remainingGames;

        for ($additionalWins = 0; $additionalWins <= $remainingGames; $additionalWins++) {
            $total = $wins + $additionalWins;
            if ($total > $line) {
                $over += $probability;
            } elseif ($total < $line) {
                $under += $probability;
            } else {
                $push += $probability;
            }

            $probability *= (($remainingGames - $additionalWins) / ($additionalWins + 1)) * ($winPct / (1 - $winPct));
        }

        $mass = $over + $under + $push;

        return [$over / $mass, $under / $mass, $push / $mass];
    }

    protected function sigmoid(float $value): float
    {
        return 1.0 / (1.0 + exp(-$value));
    }
}
