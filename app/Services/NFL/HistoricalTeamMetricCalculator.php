<?php

namespace App\Services\NFL;

use App\Actions\Sports\Concerns\CalculatesGridironTeamMetrics;
use App\Concerns\FiltersTeamGames;
use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class HistoricalTeamMetricCalculator
{
    use CalculatesGridironTeamMetrics;
    use FiltersTeamGames;

    public function __construct(
        protected TeamOffseasonSignalService $teamOffseasonSignalService
    ) {}

    /**
     * @return array<int, array<string, int|float|string|null>>
     */
    public function calculateForDate(int $season, CarbonInterface|string $asOfDate): array
    {
        $timestamp = Carbon::parse($asOfDate);
        $teams = Team::query()->orderBy('id')->get();
        if ($teams->isEmpty()) {
            return [];
        }

        $asOfEloByTeam = $this->asOfEloMap($teams, $timestamp);
        $priorSeasonMetrics = $this->priorSeasonMetricMap($season);
        $offseasonSignals = $this->teamOffseasonSignalService->signalsForSeason($season, $timestamp);
        $leagueAverageElo = $asOfEloByTeam !== []
            ? array_sum($asOfEloByTeam) / count($asOfEloByTeam)
            : 1500.0;

        $rows = [];

        foreach ($teams as $team) {
            $games = $this->completedGamesForTeamAt($team, $season, $timestamp);
            $record = $this->calculateWinLossRecord($games, $team);
            $gamesPlayed = $record['wins'] + $record['losses'];
            $currentRecentFormRating = $this->calculateRecentFormRating($games, $team);
            $futureSos = $this->calculateFutureStrengthOfScheduleAt($team, $season, $timestamp, $asOfEloByTeam);

            $currentPredictiveRating = null;

            if ($games->isNotEmpty()) {
                extract($this->gatherHistoricalTeamStatsFromGames($games, $team, $asOfEloByTeam));

                $pointsScored = [];
                $pointsAllowed = [];

                foreach ($games as $game) {
                    $isHome = (int) $game->home_team_id === (int) $team->id;
                    $pointsScored[] = $isHome ? (float) ($game->home_score ?? 0) : (float) ($game->away_score ?? 0);
                    $pointsAllowed[] = $isHome ? (float) ($game->away_score ?? 0) : (float) ($game->home_score ?? 0);
                }

                $netRating = $this->calculateAverage($pointsScored) - $this->calculateAverage($pointsAllowed);
                $turnoverDifferential = $this->calculateTurnoverDifferential($teamStats, $opponentStats);
                $seasonSos = $this->calculateStrengthOfSchedule($opponentElos, 3);

                $currentPredictiveRating = $this->calculatePredictiveRating(
                    netRating: $netRating,
                    recentFormRating: $currentRecentFormRating,
                    turnoverDifferential: $turnoverDifferential,
                    seasonSos: $seasonSos,
                    leagueAverageElo: $leagueAverageElo
                );
            }

            $priorMetric = $priorSeasonMetrics[(int) $team->id] ?? null;
            $basePredictiveRating = $this->blendWithPriorSeasonMetric(
                currentValue: $currentPredictiveRating,
                priorValue: $priorMetric['predictive_rating'] ?? null,
                gamesPlayed: $gamesPlayed,
                priorScale: (float) config('nfl.team_futures.preseason_prior_predictive_decay', 1.0),
            );
            $recentFormRating = $this->blendWithPriorSeasonMetric(
                currentValue: $currentRecentFormRating,
                priorValue: $priorMetric['recent_form_rating'] ?? null,
                gamesPlayed: $gamesPlayed,
                priorScale: (float) config('nfl.team_futures.preseason_prior_recent_form_decay', 0.35),
            );
            $offseasonSignal = $offseasonSignals[(int) $team->id] ?? [];
            $predictiveRating = $basePredictiveRating;
            if ($predictiveRating !== null) {
                $predictiveRating += (float) ($offseasonSignal['offseason_adjustment'] ?? 0.0);
            } elseif (($offseasonSignal['offseason_adjustment'] ?? 0.0) !== 0.0) {
                $predictiveRating = (float) $offseasonSignal['offseason_adjustment'];
            }
            $injuryTotalAdjustment = (float) ($offseasonSignal['injury_adjustment'] ?? 0.0);

            $rows[(int) $team->id] = [
                'team_id' => (int) $team->id,
                'season' => $season,
                'wins' => (int) $record['wins'],
                'losses' => (int) $record['losses'],
                'predictive_rating' => $predictiveRating !== null ? round($predictiveRating, 3) : null,
                'future_strength_of_schedule' => $futureSos !== null ? round($futureSos, 3) : null,
                'recent_form_rating' => $recentFormRating !== null ? round($recentFormRating, 3) : null,
                'injury_total_adjustment' => round($injuryTotalAdjustment, 3),
                'calculation_date' => $timestamp->toDateString(),
                'captured_at' => $timestamp,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array{predictive_rating:?float,recent_form_rating:?float}>
     */
    protected function priorSeasonMetricMap(int $season): array
    {
        $lookbackSeasons = max(1, (int) config('nfl.team_futures.preseason_prior_lookback_seasons', 2));
        $decay = max(0.0, (float) config('nfl.team_futures.preseason_prior_season_decay', 0.55));

        $priorSeasons = collect(range(1, $lookbackSeasons))
            ->map(fn (int $offset) => $season - $offset)
            ->filter(fn (int $priorSeason) => $priorSeason > 0)
            ->values();

        if ($priorSeasons->isEmpty()) {
            return [];
        }

        $rows = \App\Models\NFL\TeamMetric::query()
            ->whereIn('season', $priorSeasons->all())
            ->where('season_type', (string) config('nfl.season.types.regular', 2))
            ->orderByDesc('calculation_date')
            ->orderByDesc('id')
            ->get([
                'team_id',
                'season',
                'predictive_rating',
                'recent_form_rating',
            ]);

        $latestByTeamSeason = [];
        foreach ($rows as $row) {
            $teamId = (int) ($row->team_id ?? 0);
            $metricSeason = (int) ($row->season ?? 0);
            if ($teamId <= 0 || $metricSeason <= 0 || isset($latestByTeamSeason[$teamId][$metricSeason])) {
                continue;
            }

            $latestByTeamSeason[$teamId][$metricSeason] = [
                'predictive_rating' => $row->predictive_rating !== null ? (float) $row->predictive_rating : null,
                'recent_form_rating' => $row->recent_form_rating !== null ? (float) $row->recent_form_rating : null,
            ];
        }

        $byTeam = [];
        foreach ($latestByTeamSeason as $teamId => $seasonMetrics) {
            $predictiveWeight = 0.0;
            $recentFormWeight = 0.0;
            $predictiveTotal = 0.0;
            $recentFormTotal = 0.0;

            foreach ($priorSeasons as $priorSeason) {
                $metric = $seasonMetrics[$priorSeason] ?? null;
                if (! is_array($metric)) {
                    continue;
                }

                $seasonOffset = max(1, $season - $priorSeason);
                $weight = $seasonOffset === 1
                    ? 1.0
                    : pow($decay, $seasonOffset - 1);

                $predictiveRating = $metric['predictive_rating'] ?? null;
                if ($predictiveRating !== null) {
                    $predictiveTotal += ((float) $predictiveRating) * $weight;
                    $predictiveWeight += $weight;
                }

                $recentFormRating = $metric['recent_form_rating'] ?? null;
                if ($recentFormRating !== null) {
                    $recentFormTotal += ((float) $recentFormRating) * $weight;
                    $recentFormWeight += $weight;
                }
            }

            $byTeam[(int) $teamId] = [
                'predictive_rating' => $predictiveWeight > 0.0 ? ($predictiveTotal / $predictiveWeight) : null,
                'recent_form_rating' => $recentFormWeight > 0.0 ? ($recentFormTotal / $recentFormWeight) : null,
            ];
        }

        return $byTeam;
    }

    protected function completedGamesForTeamAt(Team $team, int $season, CarbonInterface $asOfDate): EloquentCollection
    {
        $seasonTypeCandidates = $this->resolveAnalyticsSeasonTypeCandidates('nfl');

        return Game::query()
            ->where('season', $season)
            ->where('status', config('nfl.statuses.final'))
            ->where('game_date', '<=', $asOfDate)
            ->when(
                $seasonTypeCandidates !== [],
                fn ($query) => $query->whereIn('season_type', $seasonTypeCandidates)
            )
            ->where(function ($query) use ($team): void {
                $query->where('home_team_id', $team->id)
                    ->orWhere('away_team_id', $team->id);
            })
            ->with(['teamStats', 'homeTeam', 'awayTeam'])
            ->orderBy('game_date')
            ->get();
    }

    /**
     * @param  array<int, float>  $asOfEloByTeam
     * @return array{teamStats:array<int, mixed>,opponentStats:array<int, mixed>,opponentElos:array<int, float>}
     */
    protected function gatherHistoricalTeamStatsFromGames(Collection $games, Team $team, array $asOfEloByTeam): array
    {
        $teamStats = [];
        $opponentStats = [];
        $opponentElos = [];
        $perGameElos = $this->loadPerGameEloRatings($games, $team);

        foreach ($games as $game) {
            $isHome = (int) $game->home_team_id === (int) $team->id;
            $teamStat = $game->teamStats->firstWhere('team_id', $team->id);
            if (! $teamStat) {
                $teamStat = $this->statByTeamType($game->teamStats, $isHome ? 'home' : 'away');
            }

            $opponentId = (int) ($isHome ? $game->away_team_id : $game->home_team_id);
            $opponentStat = $game->teamStats->firstWhere('team_id', $opponentId);
            if (! $opponentStat) {
                $opponentStat = $this->statByTeamType($game->teamStats, $isHome ? 'away' : 'home');
            }

            if ($teamStat) {
                $teamStats[] = $teamStat;
            }

            if ($opponentStat) {
                $opponentStats[] = $opponentStat;
            }

            $eloKey = $game->id.'-'.$opponentId;
            $opponentElos[] = $perGameElos[$eloKey] ?? ($asOfEloByTeam[$opponentId] ?? 1500.0);
        }

        return compact('teamStats', 'opponentStats', 'opponentElos');
    }

    /**
     * @param  array<int, float>  $asOfEloByTeam
     */
    protected function calculateFutureStrengthOfScheduleAt(
        Team $team,
        int $season,
        CarbonInterface $asOfDate,
        array $asOfEloByTeam
    ): ?float {
        $seasonTypeCandidates = $this->resolveAnalyticsSeasonTypeCandidates('nfl');

        $upcoming = Game::query()
            ->where('season', $season)
            ->where('game_date', '>', $asOfDate)
            ->when(
                $seasonTypeCandidates !== [],
                fn ($query) => $query->whereIn('season_type', $seasonTypeCandidates)
            )
            ->where(function ($query) use ($team): void {
                $query->where('home_team_id', $team->id)
                    ->orWhere('away_team_id', $team->id);
            })
            ->get(['home_team_id', 'away_team_id']);

        if ($upcoming->isEmpty()) {
            return null;
        }

        $opponentElos = $upcoming
            ->map(function (Game $game) use ($team, $asOfEloByTeam) {
                $opponentId = (int) ((int) $game->home_team_id === (int) $team->id
                    ? $game->away_team_id
                    : $game->home_team_id);

                return $asOfEloByTeam[$opponentId] ?? 1500.0;
            })
            ->filter(fn ($elo) => $elo !== null)
            ->values();

        if ($opponentElos->isEmpty()) {
            return null;
        }

        return (float) $opponentElos->avg();
    }

    /**
     * @param  EloquentCollection<int, Team>  $teams
     * @return array<int, float>
     */
    protected function asOfEloMap(EloquentCollection $teams, CarbonInterface $asOfDate): array
    {
        $teamIds = $teams->pluck('id')->map(fn ($id) => (int) $id)->all();

        $eloRows = EloRating::query()
            ->whereIn('team_id', $teamIds)
            ->whereDate('date', '<=', $asOfDate->toDateString())
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get(['team_id', 'elo_rating']);

        $byTeam = [];
        foreach ($eloRows as $row) {
            $teamId = (int) ($row->team_id ?? 0);
            if ($teamId <= 0 || isset($byTeam[$teamId])) {
                continue;
            }

            $byTeam[$teamId] = (float) ($row->elo_rating ?? 1500.0);
        }

        foreach ($teamIds as $teamId) {
            $byTeam[$teamId] ??= 1500.0;
        }

        return $byTeam;
    }

    protected function calculatePredictiveRating(
        float $netRating,
        ?float $recentFormRating,
        float $turnoverDifferential,
        ?float $seasonSos,
        float $leagueAverageElo
    ): float {
        $sosAdjustment = $seasonSos === null
            ? 0.0
            : (($seasonSos - $leagueAverageElo) / 25.0);

        return ($netRating * 0.65)
            + (($recentFormRating ?? 0.0) * 0.25)
            + ($turnoverDifferential * 0.75)
            + $sosAdjustment;
    }

    protected function blendWithPriorSeasonMetric(
        ?float $currentValue,
        ?float $priorValue,
        int $gamesPlayed,
        float $priorScale = 1.0
    ): ?float {
        $scaledPriorValue = $priorValue !== null ? ($priorValue * $priorScale) : null;

        if ($currentValue === null && $scaledPriorValue === null) {
            return null;
        }

        if ($currentValue === null) {
            return $scaledPriorValue;
        }

        if ($scaledPriorValue === null) {
            return $currentValue;
        }

        $stabilizationGames = max(1.0, (float) config('nfl.team_futures.preseason_prior_games', 6));
        $currentWeight = min(1.0, max(0.0, $gamesPlayed / $stabilizationGames));
        $priorWeight = 1.0 - $currentWeight;

        return ($currentValue * $currentWeight) + ($scaledPriorValue * $priorWeight);
    }
}
