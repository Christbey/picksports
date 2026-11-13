<?php

namespace App\Services\CFB\Data;

use App\Models\CFB\FpiRating;
use App\Models\CFB\Game;
use App\Models\CFB\TeamMetric;
use App\Models\CFB\TeamSeasonAffiliation;
use Carbon\CarbonImmutable;

class CfbStoredPregameInputs
{
    public function assess(Game $game, CarbonImmutable $now): array
    {
        $cutoff = $game->sportEvent?->starts_at;
        if ($cutoff === null) {
            return ['holds' => ['game' => ['unverified_kickoff']], 'observations' => []];
        }

        $observations = [];
        $holds = [];
        $minimumDate = $now->subDays(max(1, (int) config('cfb.data.stored_pregame_max_age_days', 14)));

        foreach (array_unique([$game->home_team_id, $game->away_team_id]) as $teamId) {
            $affiliation = TeamSeasonAffiliation::where('team_id', $teamId)->where('season', $game->season)
                ->where('source', 'cfbd_fbs_membership')->where('updated_at', '<=', $now)->first();

            if (! in_array($affiliation?->subdivision, ['FBS', 'NON_FBS'], true)
                || ($affiliation->subdivision === 'FBS' && blank($affiliation->conference))) {
                $holds[$teamId][] = 'unverified_affiliation';

                continue;
            }
            if ($affiliation->subdivision === 'NON_FBS') {
                continue;
            }

            $rating = FpiRating::where('team_id', $teamId)->where('season', $game->season)
                ->where('week', '<=', $game->week)->where('updated_at', '<=', $now)
                ->where('created_at', '<=', $now)->where('created_at', '<', $cutoff)
                ->where('updated_at', '<', $cutoff)->orderByDesc('week')->orderByDesc('updated_at')->first();
            $metric = TeamMetric::where('team_id', $teamId)->where('season', $game->season)
                ->where('updated_at', '<=', $now)->where('updated_at', '<', $cutoff)
                ->whereDate('calculation_date', '<=', $now->toDateString())
                ->orderByDesc('calculation_date')->orderByDesc('id')->first();

            $observations[$teamId] = [
                'fpi_observed_at' => $rating?->updated_at?->toIso8601String(),
                'metrics_observed_at' => $metric?->updated_at?->toIso8601String(),
            ];
            if (! is_numeric($rating?->fpi) || $rating->updated_at->lt($minimumDate)) {
                $holds[$teamId][] = 'missing_or_stale_fpi';
            }
            if ($metric === null || $metric->updated_at->lt($minimumDate)
                || $metric->calculation_date?->toDateString() < $minimumDate->toDateString()) {
                $holds[$teamId][] = 'missing_or_stale_team_metrics';
            }
        }

        return ['holds' => $holds, 'observations' => $observations];
    }
}
