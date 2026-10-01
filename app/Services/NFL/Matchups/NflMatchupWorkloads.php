<?php

namespace App\Services\NFL\Matchups;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class NflMatchupWorkloads
{
    /** Official offensive plays = attempts + rushes + sacks; includes spikes/kneels, not penalty-only rows. */
    public function forGames(Collection $games): array
    {
        $result = [];
        $stats = DB::table('nfl_team_stats')->whereIn('game_id', $games->pluck('id'))->get()->groupBy('game_id');
        foreach ($games as $game) {
            $rows = $stats->get($game->id, collect());
            if ($rows->count() !== 2 || $rows->pluck('team_id')->unique()->count() !== 2) {
                continue;
            }
            foreach ($rows as $row) {
                $values = [$row->passing_attempts, $row->rushing_attempts, $row->sacks_allowed];
                if (! in_array((int) $row->team_id, [(int) $game->home_team_id, (int) $game->away_team_id], true)
                    || count(array_filter($values, fn ($v) => is_numeric($v) && $v >= 0)) !== 3) {
                    continue;
                }
                $result[$game->id][$row->team_id] = ['snaps' => array_sum($values), 'source' => 'nfl_team_stats', 'stat_id' => $row->id];
            }
            if (count($result[$game->id] ?? []) !== 2) {
                unset($result[$game->id]);
            }
        }

        return $result;
    }
}
