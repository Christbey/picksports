<?php

namespace App\Http\Controllers\Api\NFL;

use App\Actions\NFL\CalculateTeamTrends;
use App\Http\Controllers\Controller;
use App\Http\Resources\NFL\TeamResource;
use App\Models\NFL\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    /**
     * Display a listing of NFL teams.
     */
    public function index()
    {
        $teams = Team::query()
            ->orderBy('name')
            ->paginate(15);

        return TeamResource::collection($teams);
    }

    /**
     * Display the specified NFL team.
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
        $gameCount = $request->integer('games', config('trends.defaults.sample_size', 16));
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
            'team_name' => $team->name,
            'sample_size' => $gameCount,
            'user_tier' => $userTier,
            'trends' => $result['trends'],
            'locked_trends' => $result['locked'],
        ]);
    }
}
