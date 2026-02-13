<?php

namespace App\Http\Controllers\Api\WCBB;

use App\Http\Controllers\Controller;
use App\Http\Resources\WCBB\TeamMetricResource;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamMetric;

class TeamMetricController extends Controller
{
    /**
     * Display a listing of WCBB team metrics.
     */
    public function index()
    {
        $user = auth()->user();
        $tier = $user?->subscriptionTier();
        $tierLimit = $tier?->getTeamMetricsLimit();

        $query = TeamMetric::query()
            ->with(['team'])
            ->orderByDesc('net_rating');

        // Apply tier limit to total results
        if ($tierLimit !== null) {
            $query->limit($tierLimit);
        }

        $metrics = $query->get();

        return TeamMetricResource::collection($metrics)->additional([
            'tier_limit' => $tierLimit,
            'tier_name' => $tier?->name,
        ]);
    }

    /**
     * Display the specified WCBB team metric.
     */
    public function show(TeamMetric $teamMetric)
    {
        $teamMetric->load(['team']);

        return new TeamMetricResource($teamMetric);
    }

    /**
     * Display team metrics for a specific team.
     */
    public function byTeam(Team $team)
    {
        $metrics = TeamMetric::query()
            ->where('team_id', $team->id)
            ->orderByDesc('year')
            ->paginate(15);

        return TeamMetricResource::collection($metrics);
    }
}
