<?php

namespace App\Services\Validation;

use App\Models\CanonicalPrediction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CfbValidationCoverage
{
    public function requiredTeamIds(object $game): array
    {
        $ids = array_filter([$game->home_team_id, $game->away_team_id]);
        // Unknown membership remains actionable; only explicit non-FBS evidence is exempt.
        $nonFbs = DB::table('cfb_team_season_affiliations')
            ->where('season', $game->season)->whereIn('team_id', $ids)
            ->where('subdivision', 'NON_FBS')->pluck('team_id')->all();

        return array_values(array_diff($ids, $nonFbs));
    }

    public function usesCanonical(): bool
    {
        return (bool) config('prediction_lifecycle.canonical_pipeline.cfb', false)
            || (bool) config('prediction_lifecycle.canonical_reads.cfb', false);
    }

    public function publishedForGames(Collection $games): Collection
    {
        return CanonicalPrediction::query()->where('sport', 'cfb')->where('phase', 'pregame')
            ->where('publication_state', 'published')
            ->whereIn('sport_event_id', $games->pluck('sport_event_id')->filter())
            ->whereNotNull('published_at')->whereHas('markets')
            ->orderByDesc('revision')->get(['sport_event_id', 'generated_at', 'published_at', 'revision'])
            ->unique('sport_event_id')->keyBy('sport_event_id');
    }
}
