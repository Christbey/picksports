<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NflExperimentalRatingRequest;
use App\Models\NFL\TeamMetric;
use Inertia\Inertia;
use Inertia\Response;

class NflExperimentalRatingController extends Controller
{
    public function __invoke(NflExperimentalRatingRequest $request): Response
    {
        $season = (int) ($request->validated()['season'] ?? now()->year);
        // Bounded aggregate reads only: no play scans, regeneration or report jobs.
        $ratings = TeamMetric::query()->with('team:id,display_name,abbreviation')
            ->where('season', $season)->where('season_type', 2)
            ->orderBy('team_id')->limit(32)->get()
            ->map(function (TeamMetric $metric): array {
                $raw = fn (string $key): ?float => $metric->getRawOriginal($key) === null
                    ? null : (float) $metric->getRawOriginal($key);

                return [
                    'team_id' => $metric->team_id,
                    'team' => $metric->team?->display_name ?? $metric->team?->abbreviation,
                    'offense' => $raw('offensive_true_epa_per_play'),
                    'defense' => $raw('defensive_true_epa_per_play'),
                    'net' => $raw('net_true_epa_per_play'),
                    // Aggregate timestamps cannot prove when the custom EP was computed.
                    'custom_rating_calculated_at' => null,
                ];
            });

        return Inertia::render('Admin/NflExperimentalRatings', [
            'season' => $season,
            'quarantined' => (bool) config('nfl.predictions.true_epa.custom_epa_quarantined', true),
            'validation_status' => 'Not validated for pregame prediction',
            'source' => 'Historical internal custom EP aggregates; not nflverse EPA',
            'ratings' => $ratings,
        ]);
    }
}
