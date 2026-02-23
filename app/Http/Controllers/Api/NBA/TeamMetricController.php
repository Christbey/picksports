<?php

namespace App\Http\Controllers\Api\NBA;

use App\Http\Controllers\Controller;
use App\Http\Resources\NBA\TeamMetricResource;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;
use Illuminate\Support\Facades\DB;

class TeamMetricController extends Controller
{
    /**
     * Display a listing of NBA team metrics.
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

        // Calculate W-L records from completed games
        $records = collect(DB::select("
            SELECT team_id,
                SUM(CASE WHEN won = 1 THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN won = 0 THEN 1 ELSE 0 END) as losses
            FROM (
                SELECT home_team_id as team_id, CASE WHEN home_score > away_score THEN 1 ELSE 0 END as won
                FROM nba_games WHERE status = 'STATUS_FINAL'
                UNION ALL
                SELECT away_team_id as team_id, CASE WHEN away_score > home_score THEN 1 ELSE 0 END as won
                FROM nba_games WHERE status = 'STATUS_FINAL'
            ) results
            GROUP BY team_id
        "))->keyBy('team_id');

        $metrics->each(function ($metric) use ($records) {
            $record = $records->get($metric->team_id);
            $metric->setAttribute('wins', (int) ($record->wins ?? 0));
            $metric->setAttribute('losses', (int) ($record->losses ?? 0));
        });

        return TeamMetricResource::collection($metrics)->additional([
            'tier_limit' => $tierLimit,
            'tier_name' => $tier?->name,
        ]);
    }

    /**
     * Display the specified NBA team metric.
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
