<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use Carbon\CarbonInterface;

class NflPlayoffRegularSeason
{
    /**
     * @param  list<array<string, mixed>>  $teams
     * @return array{games:list<array<string, mixed>>,warnings:list<string>}
     */
    public function load(int $season, array $teams, ?CarbonInterface $asOf): array
    {
        $schedule = Game::query()->where('season', $season)
            ->where('season_type', (string) config('nfl.season.types.regular', 2))
            ->orderBy('id')->get();
        $teamIds = array_column($teams, 'team_id');
        $counts = array_fill_keys($teamIds, 0);
        $games = [];
        $warnings = [];
        foreach ($schedule as $game) {
            if (! isset($counts[$game->home_team_id], $counts[$game->away_team_id]) || $game->home_team_id === $game->away_team_id) {
                $warnings[] = 'Schedule contains teams without usable ratings.';

                continue;
            }
            $counts[$game->home_team_id]++;
            $counts[$game->away_team_id]++;
            $completedAt = $game->result_updated_at ?? $game->game_date?->copy()->endOfDay();
            $completed = $game->status === config('nfl.statuses.final', 'STATUS_FINAL')
                && ($asOf === null || ($completedAt?->lte($asOf) && $game->game_date?->lte($asOf)));
            if ($completed && ($game->home_score === null || $game->away_score === null)) {
                $warnings[] = 'A completed game is missing its score.';
            }
            if (in_array($game->status, ['STATUS_CANCELED', 'STATUS_CANCELLED'], true)) {
                $warnings[] = 'The schedule includes a cancelled game.';
            }
            $games[] = [
                'id' => $game->id, 'home' => (int) $game->home_team_id, 'away' => (int) $game->away_team_id,
                'completed' => $completed, 'home_score' => $game->home_score, 'away_score' => $game->away_score,
                'neutral' => (bool) $game->neutral_site,
            ];
        }
        $alignmentCounts = array_count_values(array_map(fn (array $team): string => $team['conference'].' '.$team['division'], $teams));
        if (count($teams) !== 32
            || count($alignmentCounts) !== 8
            || count(array_filter($alignmentCounts, fn (int $count): bool => $count !== 4)) > 0
            || count($games) !== 272
            || count(array_filter($counts, fn (int $count): bool => $count !== 17)) > 0
        ) {
            $warnings[] = 'A complete 32-team, 272-game regular-season schedule and team ratings are required.';
        }

        return ['games' => $games, 'warnings' => array_values(array_unique($warnings))];
    }

    public function sample(array $teams, array $games, array $scoreSamples, NflPlayoffRandom $random): array
    {
        $sampled = [];
        foreach ($teams as $team) {
            $sampled[$team['team_id']] = [...$team, 'sampled_wins' => 0, 'sampled_ties' => 0, 'games' => []];
        }

        foreach ($games as $game) {
            $home = $game['home'];
            $away = $game['away'];
            if ($game['completed']) {
                $points = [(int) $game['home_score'], (int) $game['away_score']];
                $touchdowns = $game['touchdowns'];
                $result = $points[0] <=> $points[1];
            } else {
                $template = $scoreSamples === [] ? null : $scoreSamples[$random->scoreIndex(count($scoreSamples))];
                $result = ($template['tied'] ?? false)
                    ? 0
                    : ($random->wins($game['home_probability']) ? 1 : -1);
                $points = $template['points'] ?? null;
                $touchdowns = $template['touchdowns'] ?? null;
                if ($result < 0) {
                    $points = $points === null ? null : array_reverse($points);
                    $touchdowns = $touchdowns === null ? null : array_reverse($touchdowns);
                }
            }

            $sampled[$home]['sampled_wins'] += (int) ($result > 0);
            $sampled[$away]['sampled_wins'] += (int) ($result < 0);
            $sampled[$home]['sampled_ties'] += (int) ($result === 0);
            $sampled[$away]['sampled_ties'] += (int) ($result === 0);
            $sampled[$home]['games'][] = ['opponent' => $away, 'result' => 1 + $result, 'points' => $points, 'touchdowns' => $touchdowns];
            $sampled[$away]['games'][] = [
                'opponent' => $home, 'result' => 1 - $result,
                'points' => $points === null ? null : array_reverse($points),
                'touchdowns' => $touchdowns === null ? null : array_reverse($touchdowns),
            ];
        }

        return $sampled;
    }
}
