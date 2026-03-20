<?php

namespace App\Actions\CBB;

use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\TeamPossessionMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CalculatePossessionMetrics
{
    private const LATE_GAME_SECONDS = 300;

    private const ROLLING_GAME_LIMIT = 10;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(int $season, ?int $gameId = null, bool $rebuild = false): array
    {
        $games = Game::query()
            ->with(['homeTeam:id', 'awayTeam:id'])
            ->where('season', $season)
            ->where('status', 'STATUS_FINAL')
            ->when($gameId !== null, fn ($query) => $query->where('id', $gameId))
            ->orderBy('game_date')
            ->orderBy('id')
            ->get();

        $seasonStats = [];
        $rollingWindows = [];
        $gameCountByTeam = [];
        $lastGameDateByTeam = [];

        foreach ($games as $game) {
            if (! $game->home_team_id || ! $game->away_team_id) {
                continue;
            }

            $possessions = $this->gamePossessions($game->id, $game->home_team_id, $game->away_team_id);
            if ($possessions->isEmpty()) {
                continue;
            }

            $byTeam = $possessions->groupBy('team_id');

            foreach ([$game->home_team_id, $game->away_team_id] as $teamId) {
                $teamPossessions = collect($byTeam->get($teamId, []));
                $opponentTeamId = $teamId === $game->home_team_id ? $game->away_team_id : $game->home_team_id;
                $opponentPossessions = collect($byTeam->get($opponentTeamId, []));

                if ($teamPossessions->isEmpty() || $opponentPossessions->isEmpty()) {
                    continue;
                }

                $gameAggregate = $this->aggregateTeamGame($teamPossessions, $opponentPossessions);

                $seasonStats[$teamId] = $this->mergeAggregate($seasonStats[$teamId] ?? $this->emptyAggregate(), $gameAggregate);

                $rollingWindows[$teamId] ??= [];
                $rollingWindows[$teamId][] = $gameAggregate;
                if (count($rollingWindows[$teamId]) > self::ROLLING_GAME_LIMIT) {
                    array_shift($rollingWindows[$teamId]);
                }

                $gameCountByTeam[$teamId] = ($gameCountByTeam[$teamId] ?? 0) + 1;
                $lastGameDateByTeam[$teamId] = $game->game_date instanceof Carbon
                    ? $game->game_date->copy()
                    : Carbon::parse((string) $game->game_date);
            }
        }

        if ($rebuild) {
            TeamPossessionMetric::query()->where('season', $season)->delete();
        }

        $rows = [];
        foreach ($seasonStats as $teamId => $aggregate) {
            $rollingAggregate = $this->mergeMany($rollingWindows[$teamId] ?? []);
            $gamesSampled = (int) ($gameCountByTeam[$teamId] ?? 0);
            $asOfDate = ($lastGameDateByTeam[$teamId] ?? now())->toDateString();

            $payload = [
                'team_id' => $teamId,
                'season' => $season,
                'as_of_date' => $asOfDate,
                'games_sampled' => $gamesSampled,
                'offensive_possessions' => $aggregate['offensive_possessions'],
                'defensive_possessions' => $aggregate['defensive_possessions'],
                'rolling_games_sampled' => min(self::ROLLING_GAME_LIMIT, $gamesSampled),
                'rolling_offensive_possessions' => $rollingAggregate['offensive_possessions'],
                'rolling_defensive_possessions' => $rollingAggregate['defensive_possessions'],
                'late_game_offensive_possessions' => $aggregate['late_game_offensive_possessions'],
                'late_game_defensive_possessions' => $aggregate['late_game_defensive_possessions'],
                'offensive_points_per_possession' => $this->rate($aggregate['offensive_points'], $aggregate['offensive_possessions']),
                'defensive_points_per_possession_allowed' => $this->rate($aggregate['defensive_points_allowed'], $aggregate['defensive_possessions']),
                'net_points_per_possession' => $this->rate($aggregate['offensive_points'], $aggregate['offensive_possessions']) - $this->rate($aggregate['defensive_points_allowed'], $aggregate['defensive_possessions']),
                'rolling_offensive_points_per_possession' => $this->rate($rollingAggregate['offensive_points'], $rollingAggregate['offensive_possessions']),
                'rolling_defensive_points_per_possession_allowed' => $this->rate($rollingAggregate['defensive_points_allowed'], $rollingAggregate['defensive_possessions']),
                'rolling_net_points_per_possession' => $this->rate($rollingAggregate['offensive_points'], $rollingAggregate['offensive_possessions']) - $this->rate($rollingAggregate['defensive_points_allowed'], $rollingAggregate['defensive_possessions']),
                'late_game_offensive_points_per_possession' => $this->rate($aggregate['late_game_offensive_points'], $aggregate['late_game_offensive_possessions']),
                'late_game_defensive_points_per_possession_allowed' => $this->rate($aggregate['late_game_defensive_points_allowed'], $aggregate['late_game_defensive_possessions']),
                'turnover_rate' => $this->rate($aggregate['offensive_turnovers'], $aggregate['offensive_possessions']),
                'forced_turnover_rate' => $this->rate($aggregate['defensive_forced_turnovers'], $aggregate['defensive_possessions']),
                'free_throw_trip_rate' => $this->rate($aggregate['offensive_foul_trips'], $aggregate['offensive_possessions']),
                'free_throw_rate_allowed' => $this->rate($aggregate['defensive_foul_trips_allowed'], $aggregate['defensive_possessions']),
                'possessions_per_game' => $gamesSampled > 0 ? round($aggregate['offensive_possessions'] / $gamesSampled, 3) : 0,
                'metadata' => [
                    'rolling_window_games' => min(self::ROLLING_GAME_LIMIT, $gamesSampled),
                    'late_game_seconds' => self::LATE_GAME_SECONDS,
                ],
            ];

            TeamPossessionMetric::query()->updateOrCreate(
                [
                    'team_id' => $teamId,
                    'season' => $season,
                    'as_of_date' => $asOfDate,
                ],
                $payload
            );

            $rows[] = $payload;
        }

        return $rows;
    }

    /**
     * @return Collection<int, array{team_id:int, points:int, seconds_remaining:int, is_turnover:bool, is_foul_trip:bool}>
     */
    private function gamePossessions(int $gameId, int $homeTeamId, int $awayTeamId): Collection
    {
        $plays = Play::query()
            ->where('game_id', $gameId)
            ->whereIn('possession_team_id', [$homeTeamId, $awayTeamId])
            ->orderBy('sequence_number')
            ->get();

        if ($plays->isEmpty()) {
            return collect();
        }

        $possessions = [];
        $current = null;
        $prevHomeScore = 0;
        $prevAwayScore = 0;

        foreach ($plays as $play) {
            $teamId = (int) $play->possession_team_id;
            $homeScore = (int) ($play->home_score ?? $prevHomeScore);
            $awayScore = (int) ($play->away_score ?? $prevAwayScore);

            if ($current === null || $current['team_id'] !== $teamId) {
                if ($current !== null) {
                    $possessions[] = $current;
                }

                $current = [
                    'team_id' => $teamId,
                    'points' => 0,
                    'seconds_remaining' => $this->secondsRemaining((int) $play->period, (string) ($play->clock ?? '0:00')),
                    'is_turnover' => false,
                    'is_foul_trip' => false,
                ];
            }

            $pointDelta = $teamId === $homeTeamId
                ? max(0, $homeScore - $prevHomeScore)
                : max(0, $awayScore - $prevAwayScore);

            $current['points'] += $pointDelta;
            $current['is_turnover'] = $current['is_turnover'] || (bool) $play->is_turnover;
            $current['is_foul_trip'] = $current['is_foul_trip']
                || ((bool) $play->is_foul && str_contains(strtolower((string) $play->play_text), 'free throw'));

            $prevHomeScore = $homeScore;
            $prevAwayScore = $awayScore;
        }

        if ($current !== null) {
            $possessions[] = $current;
        }

        return collect($possessions);
    }

    /**
     * @param  Collection<int, array{team_id:int, points:int, seconds_remaining:int, is_turnover:bool, is_foul_trip:bool}>  $teamPossessions
     * @param  Collection<int, array{team_id:int, points:int, seconds_remaining:int, is_turnover:bool, is_foul_trip:bool}>  $opponentPossessions
     * @return array<string, int>
     */
    private function aggregateTeamGame(Collection $teamPossessions, Collection $opponentPossessions): array
    {
        return [
            'offensive_points' => (int) $teamPossessions->sum('points'),
            'offensive_possessions' => $teamPossessions->count(),
            'defensive_points_allowed' => (int) $opponentPossessions->sum('points'),
            'defensive_possessions' => $opponentPossessions->count(),
            'offensive_turnovers' => $teamPossessions->where('is_turnover', true)->count(),
            'defensive_forced_turnovers' => $opponentPossessions->where('is_turnover', true)->count(),
            'offensive_foul_trips' => $teamPossessions->where('is_foul_trip', true)->count(),
            'defensive_foul_trips_allowed' => $opponentPossessions->where('is_foul_trip', true)->count(),
            'late_game_offensive_points' => (int) $teamPossessions->filter(fn ($possession) => $possession['seconds_remaining'] <= self::LATE_GAME_SECONDS)->sum('points'),
            'late_game_offensive_possessions' => $teamPossessions->filter(fn ($possession) => $possession['seconds_remaining'] <= self::LATE_GAME_SECONDS)->count(),
            'late_game_defensive_points_allowed' => (int) $opponentPossessions->filter(fn ($possession) => $possession['seconds_remaining'] <= self::LATE_GAME_SECONDS)->sum('points'),
            'late_game_defensive_possessions' => $opponentPossessions->filter(fn ($possession) => $possession['seconds_remaining'] <= self::LATE_GAME_SECONDS)->count(),
        ];
    }

    /**
     * @param  array<string, int>  $aggregate
     * @param  array<string, int>  $gameAggregate
     * @return array<string, int>
     */
    private function mergeAggregate(array $aggregate, array $gameAggregate): array
    {
        foreach ($gameAggregate as $key => $value) {
            $aggregate[$key] = (int) ($aggregate[$key] ?? 0) + $value;
        }

        return $aggregate;
    }

    /**
     * @param  array<int, array<string, int>>  $aggregates
     * @return array<string, int>
     */
    private function mergeMany(array $aggregates): array
    {
        $merged = $this->emptyAggregate();

        foreach ($aggregates as $aggregate) {
            $merged = $this->mergeAggregate($merged, $aggregate);
        }

        return $merged;
    }

    /**
     * @return array<string, int>
     */
    private function emptyAggregate(): array
    {
        return [
            'offensive_points' => 0,
            'offensive_possessions' => 0,
            'defensive_points_allowed' => 0,
            'defensive_possessions' => 0,
            'offensive_turnovers' => 0,
            'defensive_forced_turnovers' => 0,
            'offensive_foul_trips' => 0,
            'defensive_foul_trips_allowed' => 0,
            'late_game_offensive_points' => 0,
            'late_game_offensive_possessions' => 0,
            'late_game_defensive_points_allowed' => 0,
            'late_game_defensive_possessions' => 0,
        ];
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator, 3) : 0.0;
    }

    private function secondsRemaining(int $period, string $clock): int
    {
        [$minutes, $seconds] = array_pad(array_map('intval', explode(':', $clock)), 2, 0);
        $clockSeconds = ($minutes * 60) + $seconds;

        return match (true) {
            $period <= 1 => 1200 + $clockSeconds,
            $period === 2 => $clockSeconds,
            default => min(300, $clockSeconds),
        };
    }
}
