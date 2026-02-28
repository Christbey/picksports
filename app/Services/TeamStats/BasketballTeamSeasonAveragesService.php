<?php

namespace App\Services\TeamStats;

use App\Support\StatsMath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class BasketballTeamSeasonAveragesService
{
    /**
     * @param  class-string<Model>  $teamStatModel
     */
    public function allTeams(string $teamStatModel): Collection
    {
        $stats = $teamStatModel::query()
            ->selectRaw('
                team_id,
                COUNT(*) as games_played,
                AVG(points) as points_per_game,
                AVG(rebounds) as rebounds_per_game,
                AVG(assists) as assists_per_game,
                AVG(steals) as steals_per_game,
                AVG(blocks) as blocks_per_game,
                AVG(turnovers) as turnovers_per_game,
                SUM(field_goals_made) as total_fg_made,
                SUM(field_goals_attempted) as total_fg_attempted,
                SUM(three_point_made) as total_3p_made,
                SUM(three_point_attempted) as total_3p_attempted,
                SUM(free_throws_made) as total_ft_made,
                SUM(free_throws_attempted) as total_ft_attempted
            ')
            ->groupBy('team_id')
            ->get();

        return $stats->map(fn ($row) => $this->buildBasicAverages($row, includeTeamId: true))->values();
    }

    /**
     * @param  class-string<Model>  $teamStatModel
     */
    public function forTeam(string $teamStatModel, int $teamId, bool $includeTeamId = false, bool $includeFouls = false): ?array
    {
        $select = '
            team_id,
            COUNT(*) as games_played,
            AVG(points) as points_per_game,
            AVG(rebounds) as rebounds_per_game,
            AVG(assists) as assists_per_game,
            AVG(steals) as steals_per_game,
            AVG(blocks) as blocks_per_game,
            AVG(turnovers) as turnovers_per_game,
            AVG(offensive_rebounds) as offensive_rebounds_per_game,
            AVG(defensive_rebounds) as defensive_rebounds_per_game,
            AVG(fast_break_points) as fast_break_points_per_game,
            AVG(points_in_paint) as points_in_paint_per_game,
            AVG(second_chance_points) as second_chance_points_per_game,
            AVG(bench_points) as bench_points_per_game,
            SUM(field_goals_made) as total_fg_made,
            SUM(field_goals_attempted) as total_fg_attempted,
            SUM(three_point_made) as total_3p_made,
            SUM(three_point_attempted) as total_3p_attempted,
            SUM(free_throws_made) as total_ft_made,
            SUM(free_throws_attempted) as total_ft_attempted
        ';

        if ($includeFouls) {
            $select .= ', AVG(fouls) as fouls_per_game';
        }

        $row = $teamStatModel::query()
            ->where('team_id', $teamId)
            ->selectRaw($select)
            ->groupBy('team_id')
            ->first();

        if (! $row) {
            return null;
        }

        $data = [
            'games_played' => (int) $row->games_played,
            ...$this->buildBasicAverages($row),
            'offensive_rebounds_per_game' => round($row->offensive_rebounds_per_game, 1),
            'defensive_rebounds_per_game' => round($row->defensive_rebounds_per_game, 1),
            'fast_break_points_per_game' => round($row->fast_break_points_per_game, 1),
            'points_in_paint_per_game' => round($row->points_in_paint_per_game, 1),
            'second_chance_points_per_game' => round($row->second_chance_points_per_game, 1),
            'bench_points_per_game' => round($row->bench_points_per_game, 1),
        ];

        if ($includeTeamId) {
            $data = ['team_id' => $row->team_id] + $data;
        }

        if ($includeFouls) {
            $data['fouls_per_game'] = round($row->fouls_per_game, 1);
        }

        return $data;
    }

    /**
     * @return array<string, float|int>
     */
    private function buildBasicAverages(object $row, bool $includeTeamId = false): array
    {
        $data = [
            'points_per_game' => round($row->points_per_game, 1),
            'rebounds_per_game' => round($row->rebounds_per_game, 1),
            'assists_per_game' => round($row->assists_per_game, 1),
            'steals_per_game' => round($row->steals_per_game, 1),
            'blocks_per_game' => round($row->blocks_per_game, 1),
            'turnovers_per_game' => round($row->turnovers_per_game, 1),
            'field_goal_percentage' => StatsMath::percentage($row->total_fg_made, $row->total_fg_attempted),
            'three_point_percentage' => StatsMath::percentage($row->total_3p_made, $row->total_3p_attempted),
            'free_throw_percentage' => StatsMath::percentage($row->total_ft_made, $row->total_ft_attempted),
        ];

        if ($includeTeamId) {
            $data = ['team_id' => (int) $row->team_id] + $data;
        }

        return $data;
    }
}
