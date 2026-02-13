<?php

namespace App\Http\Controllers\Api\CBB;

use App\Http\Controllers\Controller;
use App\Http\Resources\CBB\TeamMetricResource;
use App\Models\CBB\Team;
use App\Models\CBB\TeamMetric;

class TeamMetricController extends Controller
{
    /**
     * Display a listing of CBB team metrics.
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
     * Display the specified CBB team metric.
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
        $metric = TeamMetric::query()
            ->where('team_id', $team->id)
            ->with(['team'])
            ->orderByDesc('season')
            ->first();

        if (! $metric) {
            return response()->json(['data' => null], 404);
        }

        return new TeamMetricResource($metric);
    }
}
