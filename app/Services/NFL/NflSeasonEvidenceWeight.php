<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\TeamMetric;
use App\Services\Sports\SportsDateWindowService;

class NflSeasonEvidenceWeight
{
    /** @return array<string, mixed> */
    public function resolve(Game $target, TeamMetric $homeMetric, TeamMetric $awayMetric, float $baseWeight): array
    {
        $enabled = (bool) config('nfl.predictions.preseason_signal.season_adaptation.enabled', true);
        $priorGames = max(1.0, (float) config('nfl.predictions.preseason_signal.season_adaptation.prior_games', 2));
        $maximum = max($baseWeight, min(1.0, (float) config('nfl.predictions.preseason_signal.season_adaptation.maximum_weight', 0.90)));
        $dates = app(SportsDateWindowService::class);
        $cutoff = $dates->gameDateTimeUtc($target->game_date, $target->game_time);
        $counts = array_fill_keys([(int) $target->home_team_id, (int) $target->away_team_id], 0);
        if ($enabled && $cutoff !== null) {
            $games = Game::query()->where('season', $target->season)
                ->whereIn('season_type', ['2', 'regular'])
                ->where('status', config('nfl.statuses.final', 'STATUS_FINAL'))
                ->where('id', '!=', $target->id)
                ->whereDate('game_date', '<=', $cutoff->toDateString())
                ->whereNotNull('home_score')->whereNotNull('away_score')
                ->where(fn ($query) => $query->whereIn('home_team_id', array_keys($counts))->orWhereIn('away_team_id', array_keys($counts)))
                ->get(['id', 'home_team_id', 'away_team_id', 'game_date', 'game_time', 'result_updated_at']);
            foreach ($games as $game) {
                $kickoff = $dates->gameDateTimeUtc($game->game_date, $game->game_time);
                if ($kickoff === null || ! $kickoff->lt($cutoff) || ($game->result_updated_at !== null && ! $game->result_updated_at->lt($cutoff))) {
                    continue;
                }
                foreach (array_keys($counts) as $teamId) {
                    if (in_array($teamId, [(int) $game->home_team_id, (int) $game->away_team_id], true)) {
                        $counts[$teamId]++;
                    }
                }
            }
        }
        $homeGames = min($counts[(int) $target->home_team_id], max(0, (int) $homeMetric->games_played));
        $awayGames = min($counts[(int) $target->away_team_id], max(0, (int) $awayMetric->games_played));
        $sample = min($homeGames, $awayGames);
        $weight = $enabled ? max($baseWeight, min($maximum, $sample / ($sample + $priorGames))) : $baseWeight;

        return [
            'policy' => 'completed-season-games-v1',
            'enabled' => $enabled,
            'weight' => $weight,
            'base_weight' => $baseWeight,
            'historical_weight' => 1 - $weight,
            'home_games' => $homeGames,
            'away_games' => $awayGames,
            'effective_games' => $sample,
            'prior_games' => $priorGames,
            'maximum_weight' => $maximum,
            'cutoff' => $cutoff?->toIso8601String(),
            'calibrated' => false,
        ];
    }
}
