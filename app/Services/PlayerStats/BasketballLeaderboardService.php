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
    public function execute(string $playerStatModel, string $playerModel, int $minGames = 10): Collection
    {
        $stats = $playerStatModel::query()
            ->selectRaw('
                player_id,
                COUNT(*) as games_played,
                AVG(points) as points_per_game,
                AVG(rebounds_total) as rebounds_per_game,
                AVG(assists) as assists_per_game,
                AVG(steals) as steals_per_game,
                AVG(blocks) as blocks_per_game,
                AVG(minutes_played) as minutes_per_game,
                SUM(field_goals_made) as total_fg_made,
                SUM(field_goals_attempted) as total_fg_attempted,
                SUM(three_point_made) as total_3p_made,
                SUM(three_point_attempted) as total_3p_attempted,
                SUM(free_throws_made) as total_ft_made,
                SUM(free_throws_attempted) as total_ft_attempted
            ')
            ->groupBy('player_id')
            ->havingRaw('COUNT(*) >= ?', [$minGames])
            ->get();

        $playerIds = $stats->pluck('player_id');
        $players = $playerModel::query()
            ->with('team')
            ->whereIn('id', $playerIds)
            ->get()
            ->keyBy('id');

        return $stats->map(function ($row) use ($players) {
            $player = $players->get($row->player_id);

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
                'steals_per_game' => round($row->steals_per_game, 1),
                'blocks_per_game' => round($row->blocks_per_game, 1),
                'minutes_per_game' => round((float) $row->minutes_per_game, 1),
                'field_goal_percentage' => StatsMath::percentage($row->total_fg_made, $row->total_fg_attempted),
                'three_point_percentage' => StatsMath::percentage($row->total_3p_made, $row->total_3p_attempted),
                'free_throw_percentage' => StatsMath::percentage($row->total_ft_made, $row->total_ft_attempted),
            ];
        })->values();
    }
}
