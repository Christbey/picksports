<?php

namespace App\Services\PlayerStats;

use App\Support\StatsMath;
use Illuminate\Support\Collection;

class BasketballLeaderboardService
{
    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $playerStatModel
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $playerModel
     */
    public function execute(
        string $playerStatModel,
        string $playerModel,
        int $minGames = 10,
        ?string $gameModel = null,
        ?int $season = null,
        ?array $seasonTypeCandidates = null
    ): Collection {
        $query = $playerStatModel::query()
            ->selectRaw('
                player_id,
                COUNT(*) as games_played,
                AVG(points) as points_per_game,
                AVG(rebounds_total) as rebounds_per_game,
                AVG(assists) as assists_per_game,
                AVG(turnovers) as turnovers_per_game,
                AVG(steals) as steals_per_game,
                AVG(blocks) as blocks_per_game,
                AVG(field_goals_made) as field_goals_made_per_game,
                AVG(field_goals_attempted) as field_goals_attempted_per_game,
                AVG(free_throws_made) as free_throws_made_per_game,
                AVG(free_throws_attempted) as free_throws_attempted_per_game,
                SUM(field_goals_made) as total_fg_made,
                SUM(field_goals_attempted) as total_fg_attempted,
                SUM(three_point_made) as total_3p_made,
                SUM(three_point_attempted) as total_3p_attempted,
                SUM(free_throws_made) as total_ft_made,
                SUM(free_throws_attempted) as total_ft_attempted
            ');

        if ($gameModel !== null) {
            $gameInstance = new $gameModel;
            $playerStatInstance = new $playerStatModel;
            $query->join(
                $gameInstance->getTable(),
                "{$gameInstance->getTable()}.id",
                '=',
                "{$playerStatInstance->getTable()}.game_id"
            );

            if ($season !== null) {
                $query->where("{$gameInstance->getTable()}.season", $season);
            }

            if (is_array($seasonTypeCandidates) && $seasonTypeCandidates !== []) {
                $query->whereIn("{$gameInstance->getTable()}.season_type", $seasonTypeCandidates);
            }
        }

        $stats = $query
            ->groupBy('player_id')
            ->havingRaw('COUNT(*) >= ?', [$minGames])
            ->get();

        $playerIds = $stats->pluck('player_id');
        $minutesAverages = $this->averageMinutesByPlayer(
            $playerStatModel,
            $playerIds->all(),
            $gameModel,
            $season,
            $seasonTypeCandidates
        );
        $players = $playerModel::query()
            ->with('team')
            ->whereIn('id', $playerIds)
            ->get()
            ->keyBy('id');

        return $stats->map(function ($row) use ($players, $minutesAverages) {
            $player = $players->get($row->player_id);
            $minutesPerGame = $minutesAverages[(int) $row->player_id] ?? 0.0;

            return [
                'player_id' => $row->player_id,
                'player' => $player ? [
                    'id' => $player->id,
                    'full_name' => $player->full_name,
                    'headshot_url' => $player->headshot_url,
                    'position' => $player->position,
                    'jersey_number' => $player->jersey_number,
                    'team' => $player->team ? [
                        'id' => $player->team->id,
                        'name' => $player->team->name,
                        'display_name' => $player->team->display_name,
                        'abbreviation' => $player->team->abbreviation,
                    ] : null,
                ] : null,
                'games_played' => (int) $row->games_played,
                'points_per_game' => round($row->points_per_game, 1),
                'rebounds_per_game' => round($row->rebounds_per_game, 1),
                'assists_per_game' => round($row->assists_per_game, 1),
                'turnovers_per_game' => round($row->turnovers_per_game, 1),
                'steals_per_game' => round($row->steals_per_game, 1),
                'blocks_per_game' => round($row->blocks_per_game, 1),
                'minutes_per_game' => round($minutesPerGame, 1),
                'field_goals_made_per_game' => round((float) $row->field_goals_made_per_game, 1),
                'field_goals_attempted_per_game' => round((float) $row->field_goals_attempted_per_game, 1),
                'free_throws_made_per_game' => round((float) $row->free_throws_made_per_game, 1),
                'free_throws_attempted_per_game' => round((float) $row->free_throws_attempted_per_game, 1),
                'field_goal_percentage' => StatsMath::percentage($row->total_fg_made, $row->total_fg_attempted),
                'three_point_percentage' => StatsMath::percentage($row->total_3p_made, $row->total_3p_attempted),
                'free_throw_percentage' => StatsMath::percentage($row->total_ft_made, $row->total_ft_attempted),
            ];
        })->values();
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $playerStatModel
     * @param  array<int,int|string>  $playerIds
     * @return array<int,float>
     */
    private function averageMinutesByPlayer(
        string $playerStatModel,
        array $playerIds,
        ?string $gameModel,
        ?int $season,
        ?array $seasonTypeCandidates = null
    ): array {
        if ($playerIds === []) {
            return [];
        }

        $query = $playerStatModel::query()
            ->select(['player_id', 'minutes_played'])
            ->whereIn('player_id', $playerIds);

        if ($gameModel !== null) {
            $gameInstance = new $gameModel;
            $playerStatInstance = new $playerStatModel;
            $query->join(
                $gameInstance->getTable(),
                "{$gameInstance->getTable()}.id",
                '=',
                "{$playerStatInstance->getTable()}.game_id"
            );

            if ($season !== null) {
                $query->where("{$gameInstance->getTable()}.season", $season);
            }

            if (is_array($seasonTypeCandidates) && $seasonTypeCandidates !== []) {
                $query->whereIn("{$gameInstance->getTable()}.season_type", $seasonTypeCandidates);
            }
        }

        /** @var array<int,array{sum:float,count:int}> $buckets */
        $buckets = [];

        foreach ($query->get() as $row) {
            $playerId = (int) $row->player_id;
            $minutes = $this->minutesToDecimal($row->minutes_played);

            if (! isset($buckets[$playerId])) {
                $buckets[$playerId] = ['sum' => 0.0, 'count' => 0];
            }

            $buckets[$playerId]['sum'] += $minutes;
            $buckets[$playerId]['count']++;
        }

        $averages = [];
        foreach ($buckets as $playerId => $bucket) {
            $count = max(1, $bucket['count']);
            $averages[$playerId] = $bucket['sum'] / $count;
        }

        return $averages;
    }

    private function minutesToDecimal(string|float|int|null $minutesPlayed): float
    {
        if ($minutesPlayed === null || $minutesPlayed === '') {
            return 0.0;
        }

        if (is_numeric($minutesPlayed)) {
            return (float) $minutesPlayed;
        }

        $value = trim((string) $minutesPlayed);
        if (! str_contains($value, ':')) {
            return is_numeric($value) ? (float) $value : 0.0;
        }

        [$mins, $secs] = array_pad(explode(':', $value, 2), 2, '0');
        $minutes = (float) (is_numeric($mins) ? $mins : 0);
        $seconds = (float) (is_numeric($secs) ? $secs : 0);

        return $minutes + (max(0.0, $seconds) / 60);
    }
}
