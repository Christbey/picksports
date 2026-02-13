<?php

namespace App\Http\Controllers\Api\NBA;

use App\Actions\NBA\CalculateTeamTrends;
use App\Http\Controllers\Controller;
use App\Http\Resources\NBA\TeamResource;
use App\Models\NBA\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    /**
     * Display a listing of NBA teams.
     */
    public function index()
    {
        $teams = Team::query()
            ->orderBy('display_name')
            ->paginate(15);

        return TeamResource::collection($teams);
    }

    /**
     * Display the specified NBA team.
     */
    public function show(Team $team)
    {
        return new TeamResource($team);
    }

    /**
     * Calculate team trends based on recent games.
     */
    public function trends(Team $team, Request $request, CalculateTeamTrends $calculator): JsonResponse
    {
        $gameCount = $request->integer('games', config('trends.defaults.sample_size', 20));
        $gameCount = min(
            max($gameCount, config('trends.defaults.min_sample', 5)),
            config('trends.defaults.max_sample', 50)
        );

        $season = $request->integer('season') ?: null;
        $beforeDate = $request->string('before_date')->toString() ?: null;

        $user = $request->user();
        $userTier = $user?->subscriptionTier()?->slug ?? config('subscriptions.default_tier', 'free');

        $result = $calculator->execute($team, $gameCount, $season, $beforeDate, $userTier);

        return response()->json([
            'team_id' => $team->id,
            'team_abbreviation' => $team->abbreviation,
            'team_name' => $team->display_name ?? $team->name,
            'sample_size' => $gameCount,
            'user_tier' => $userTier,
            'trends' => $result['trends'],
            'locked_trends' => $result['locked'],
        ]);
    }
}
