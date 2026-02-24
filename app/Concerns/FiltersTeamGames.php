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
        $sportSlug = strtolower($sport);

        return $gameModel::query()
            ->where('season', $season)
            ->where('status', config("{$sportSlug}.statuses.final"))
            ->when(
                config("{$sportSlug}.season.analytics_types"),
                fn ($query, $types) => $query->whereIn('season_type', $types)
            )
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

        // Batch-load per-game ELO ratings for accurate SOS calculation
        $perGameElos = $this->loadPerGameEloRatings($games, $team);

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

            // Use per-game ELO (pre-game rating) when available, fall back to current ELO
            $eloKey = $game->id.'-'.$opponentId;
            if (isset($perGameElos[$eloKey])) {
                $opponentElos[] = $perGameElos[$eloKey];
            } else {
                $opponent = $isHome ? $game->awayTeam : $game->homeTeam;
                if ($opponent && $opponent->elo_rating) {
                    $opponentElos[] = $opponent->elo_rating;
                }
            }
        }

        return compact('teamStats', 'opponentStats', 'opponentElos');
    }

    /**
     * Load per-game ELO ratings for opponents, returning pre-game ELO values.
     *
     * @return array<string, float> Keyed by "game_id-team_id" with pre-game ELO values
     */
    protected function loadPerGameEloRatings(Collection $games, Model $team): array
    {
        if ($games->isEmpty()) {
            return [];
        }

        $sport = class_basename((new \ReflectionClass($team))->getNamespaceName());
        $eloRatingModel = "App\\Models\\{$sport}\\EloRating";

        if (! class_exists($eloRatingModel)) {
            return [];
        }

        $gameIds = $games->pluck('id')->toArray();

        return $eloRatingModel::query()
            ->whereIn('game_id', $gameIds)
            ->where('team_id', '!=', $team->id)
            ->get()
            ->mapWithKeys(function ($record) {
                // Pre-game ELO = post-game ELO minus the change from this game
                $preGameElo = (float) $record->elo_rating - (float) $record->elo_change;

                return [$record->game_id.'-'.$record->team_id => $preGameElo];
            })
            ->toArray();
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
