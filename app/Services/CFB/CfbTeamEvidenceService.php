<?php

namespace App\Services\CFB;

use App\Models\CFB\Game;

class CfbTeamEvidenceService
{
    /** Descriptive windows only; never add overlapping windows as independent model evidence. */
    public function forGame(Game $target, int $teamId): array
    {
        $games = Game::where('status', 'STATUS_FINAL')->whereNotNull('home_score')->whereNotNull('away_score')
            ->whereIn('season_type', ['2', 'regular'])
            ->whereBetween('season', [$target->season - 3, $target->season])
            ->whereDate('game_date', '<', $target->game_date)
            ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->orderByDesc('game_date')->orderByDesc('game_time')->get();

        return collect([
            'last_5' => $games->take(5), 'last_10' => $games->take(10),
            'this_season' => $games->where('season', $target->season),
            'historical' => $games->where('season', '<', $target->season),
        ])->map(function ($sample) use ($teamId) {
            $scored = $sample->map(fn ($g) => $g->home_team_id === $teamId ? $g->home_score : $g->away_score);
            $allowed = $sample->map(fn ($g) => $g->home_team_id === $teamId ? $g->away_score : $g->home_score);
            $margins = $scored->values()->map(fn ($score, $i) => $score - $allowed->values()[$i]);

            return ['games' => $sample->count(), 'game_ids' => $sample->pluck('id')->values()->all(),
                'dates' => $sample->map(fn ($g) => $g->game_date->toDateString())->values()->all(),
                'seasons' => $sample->pluck('season')->unique()->values()->all(),
                'wins' => $margins->filter(fn ($v) => $v > 0)->count(),
                'losses' => $margins->filter(fn ($v) => $v < 0)->count(),
                'ties' => $margins->filter(fn ($v) => $v === 0)->count(),
                'points_per_game' => $sample->isEmpty() ? null : round($scored->avg(), 2),
                'points_allowed_per_game' => $sample->isEmpty() ? null : round($allowed->avg(), 2)];
        })->all();
    }
}
