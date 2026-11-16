<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use Carbon\CarbonInterface;

class NflPostseasonGames
{
    /**
     * @param  list<int>  $teamIds
     * @return array<string, array{home:int,neutral:bool|null,winner:int|null,conflict:bool}>
     */
    public function atCutoff(int $season, CarbonInterface $cutoff, array $teamIds): array
    {
        $final = config('nfl.statuses.final', 'STATUS_FINAL');
        $scheduled = config('nfl.statuses.scheduled', 'STATUS_SCHEDULED');
        $games = Game::query()
            ->where('season', $season)
            ->where('season_type', config('nfl.season.types.postseason', 3))
            ->where(function ($query) use ($cutoff, $final, $scheduled): void {
                $query->where(function ($query) use ($cutoff, $final): void {
                    $query->where('status', $final)
                        ->where('game_date', '<=', $cutoff)
                        ->where('result_updated_at', '<=', $cutoff)
                        ->whereNotNull('home_score')
                        ->whereNotNull('away_score')
                        ->whereColumn('home_score', '!=', 'away_score');
                })->orWhere(function ($query) use ($cutoff, $scheduled): void {
                    // Mutable schedule rows need a revision timestamp at or before the cutoff.
                    $query->where('status', $scheduled)->where('updated_at', '<=', $cutoff);
                });
            })
            ->get(['home_team_id', 'away_team_id', 'home_score', 'away_score', 'neutral_site', 'status']);

        $eligibleTeams = array_fill_keys($teamIds, true);
        $known = [];
        foreach ($games as $game) {
            $home = (int) $game->home_team_id;
            $away = (int) $game->away_team_id;
            if ($home === $away || ! isset($eligibleTeams[$home], $eligibleTeams[$away])) {
                continue;
            }

            $key = NflPlayoffBracket::matchupKey($home, $away);
            $winner = $game->status === $final ? ($game->home_score > $game->away_score ? $home : $away) : null;
            $record = ['home' => $home, 'neutral' => $game->neutral_site, 'winner' => $winner, 'conflict' => false];
            $existing = $known[$key] ?? null;

            if ($existing !== null && (($existing['winner'] === null) === ($winner === null))) {
                $known[$key]['conflict'] = $existing['conflict']
                    || ($winner === null ? $existing !== $record : $existing['winner'] !== $winner);
            } elseif ($existing === null || $winner !== null) {
                $known[$key] = $record;
            }
        }

        return $known;
    }
}
