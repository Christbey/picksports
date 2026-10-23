<?php

namespace App\Services\BettingRecommendations;

use App\Models\NFL\Player;
use Illuminate\Support\Collection;

class NflPropRoleHistory
{
    /** Do not confuse a known relief appearance with a normal starting workload. */
    public function select(Collection $history, Player $player, ?string $expectedQuarterback = null): array
    {
        $starter = fn ($row) => (int) $row->team_id === (int) $row->game->home_team_id
            ? $row->game->home_qb_name : $row->game->away_qb_name;
        $same = fn (?string $a, ?string $b) => filled($a) && filled($b) && strcasecmp(trim($a), trim($b)) === 0;
        $known = $history->filter(fn ($row) => filled($starter($row)));
        $selected = $history;
        $reason = 'all_reported_appearances';
        if ($player->position === 'QB') {
            $selected = $history->filter(fn ($row) => blank($starter($row)) || $same($starter($row), $player->full_name));
            $reason = 'known_relief_appearances_excluded';
        } elseif (in_array($player->position, ['WR', 'TE', 'RB'], true) && filled($expectedQuarterback)) {
            $matched = $history->filter(fn ($row) => $same($starter($row), $expectedQuarterback));
            if ($matched->count() >= 3) {
                $selected = $matched;
                $reason = 'same_quarterback_history';
            } else {
                $reason = 'insufficient_same_quarterback_history';
            }
        }

        return ['history' => $selected->values(), 'evidence' => [
            'reason' => $reason,
            'input_games' => $history->count(),
            'selected_games' => $selected->count(),
            'excluded_game_ids' => $history->diff($selected)->pluck('game_id')->values()->all(),
            'unknown_starter_games' => $history->count() - $known->count(),
            'expected_quarterback' => $expectedQuarterback,
            'limitation' => 'snap_counts_and_injury_exit_times_unavailable; low_volume_is_not_proof_of_injury',
        ]];
    }
}
