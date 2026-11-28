<?php

namespace App\Services\NFL;

use App\DataTransferObjects\ESPN\GameData;
use App\Models\NFL\Game;
use Carbon\CarbonInterface;

class RegularSeasonFuturesResults
{
    public static function gamesPerTeam(int $season): int
    {
        return $season < 2021 ? 16 : (int) config('nfl.team_futures.default_regular_season_games', 17);
    }

    /**
     * @return array<int, array{games: int, wins: int, losses: int, ties: int, settled_at: CarbonInterface}>
     */
    public function records(int $season, CarbonInterface $cutoff, bool $requireBoxScores = false): array
    {
        $games = Game::query()
            ->where('season', $season)
            ->where('season_type', config('nfl.season.types.regular', 2))
            ->whereIn('status', GameData::finalStatuses())
            ->where('game_date', '<=', $cutoff)
            ->where('result_updated_at', '<=', $cutoff)
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->when($requireBoxScores, fn ($query) => $query
                ->whereHas('playerStats', fn ($stats) => $stats->whereColumn('nfl_player_stats.team_id', 'nfl_games.home_team_id'))
                ->whereHas('playerStats', fn ($stats) => $stats->whereColumn('nfl_player_stats.team_id', 'nfl_games.away_team_id')))
            ->get(['home_team_id', 'away_team_id', 'home_score', 'away_score', 'result_updated_at']);

        $records = [];
        foreach ($games as $game) {
            $knownAt = $game->result_updated_at;
            $homeResult = $game->home_score <=> $game->away_score;
            foreach ([$game->home_team_id => $homeResult, $game->away_team_id => -$homeResult] as $teamId => $result) {
                $record = $records[$teamId] ?? ['games' => 0, 'wins' => 0, 'losses' => 0, 'ties' => 0, 'settled_at' => $knownAt];
                $record['games']++;
                $record[match ($result) {
                    1 => 'wins',
                    -1 => 'losses',
                    0 => 'ties',
                }]++;
                $record['settled_at'] = $record['settled_at']->max($knownAt);
                $records[$teamId] = $record;
            }
        }

        return $records;
    }

    public function hasSettledSeasonWithBoxScores(int $season, CarbonInterface $cutoff): bool
    {
        $records = $this->records($season, $cutoff, requireBoxScores: true);
        $gamesPerTeam = self::gamesPerTeam($season);

        return count($records) === (int) config('nfl.season.team_count', 32)
            && array_all($records, fn (array $record): bool => $record['games'] === $gamesPerTeam);
    }
}
