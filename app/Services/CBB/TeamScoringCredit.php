<?php

namespace App\Services\CBB;

use App\Models\CBB\Game;

class TeamScoringCredit
{
    /** Accept an unattributed team basket only when every scoring event reconciles to both final scores. */
    public function points(Game $game, int $teamId): int
    {
        $plays = $game->plays()->orderBy('sequence_number')->get();
        $first = $plays->first();
        $last = $plays->last();
        if ($game->status !== 'STATUS_FINAL' || $first === null || $last === null
            || $first->period !== 1 || $first->clock !== '20:00'
            || $first->home_score !== 0 || $first->away_score !== 0
            || $last->period < 2 || ! in_array($last->clock, ['0:00', '00:00'], true)
            || $last->home_score !== $game->home_score || $last->away_score !== $game->away_score) {
            return 0;
        }
        $totals = [(int) $game->home_team_id => 0, (int) $game->away_team_id => 0];
        $credit = 0;
        foreach ($plays as $play) {
            if (! $play->made_shot) {
                continue;
            }
            $id = (int) $play->possession_team_id;
            if (! array_key_exists($id, $totals) || ! in_array($play->score_value, [1, 2, 3], true)) {
                return 0;
            }
            $totals[$id] += $play->score_value;
            if ($id === $teamId && $play->score_value === 2 && $play->shooting_play
                && $play->play_type === 'TipShot' && preg_match('/^team\s+makes\b/i', $play->play_text ?? '')) {
                $credit += 2;
            }
        }

        return $totals[(int) $game->home_team_id] === $game->home_score
            && $totals[(int) $game->away_team_id] === $game->away_score ? $credit : 0;
    }
}
