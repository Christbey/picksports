<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

trait FiltersTeamGames
{
    /**
     * Get completed games for a team in a specific season.
     */
    protected function getCompletedGamesForTeam(
        Model $team,
        int $season,
        string $sport
    ): Collection {
        $gameModel = "App\\Models\\{$sport}\\Game";

        return $gameModel::query()
            ->where('season', $season)
            ->where('status', config(strtolower($sport).'.statuses.final'))
            ->where(function ($query) use ($team) {
                $query->where('home_team_id', $team->id)
                    ->orWhere('away_team_id', $team->id);
            })
            ->with(['teamStats', 'homeTeam', 'awayTeam'])
            ->get();
    }

    /**
     * Gather team and opponent stats from games.
     */
    protected function gatherTeamStatsFromGames(
        Collection $games,
        Model $team
    ): array {
        $teamStats = [];
        $opponentStats = [];
        $opponentElos = [];

        foreach ($games as $game) {
            $isHome = $game->home_team_id === $team->id;

            $teamStat = $game->teamStats->firstWhere('team_id', $team->id);
            $opponentId = $isHome ? $game->away_team_id : $game->home_team_id;
            $opponentStat = $game->teamStats->firstWhere('team_id', $opponentId);

            if ($teamStat) {
                $teamStats[] = $teamStat;
            }

            if ($opponentStat) {
                $opponentStats[] = $opponentStat;
            }

            $opponent = $isHome ? $game->awayTeam : $game->homeTeam;
            if ($opponent && $opponent->elo_rating) {
                $opponentElos[] = $opponent->elo_rating;
            }
        }

        return compact('teamStats', 'opponentStats', 'opponentElos');
    }

    /**
     * Calculate strength of schedule from opponent ELOs.
     */
    protected function calculateStrengthOfSchedule(array $opponentElos, int $precision = 3): ?float
    {
        if (empty($opponentElos)) {
            return null;
        }

        return round(array_sum($opponentElos) / count($opponentElos), $precision);
    }
}
