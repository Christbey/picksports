<?php

namespace App\Http\Controllers\Api\NBA;

use App\Http\Controllers\Controller;
use App\Http\Resources\NBA\EloRatingResource;
use App\Models\NBA\EloRating;
use App\Models\NBA\Team;
use Illuminate\Http\Request;

class EloRatingController extends Controller
{
    /**
     * Display a listing of NBA Elo ratings.
     */
    public function index()
    {
        $ratings = EloRating::query()
            ->with(['team'])
            ->orderByDesc('date')
            ->paginate(15);

        return EloRatingResource::collection($ratings);
    }

    /**
     * Display the specified NBA Elo rating.
     */
    public function show(EloRating $eloRating)
    {
        $eloRating->load(['team']);

        return new EloRatingResource($eloRating);
    }

    /**
     * Display Elo ratings for a specific team.
     */
    public function byTeam(Team $team)
    {
        $ratings = EloRating::query()
            ->where('team_id', $team->id)
            ->orderByDesc('date')
            ->paginate(15);

        return EloRatingResource::collection($ratings);
    }

    /**
     * Display Elo ratings for a specific season.
     */
    public function bySeason(Request $request)
    {
        $season = $request->input('season');

        $ratings = EloRating::query()
            ->with(['team'])
            ->where('season', $season)
            ->orderByDesc('date')
            ->paginate(15);

        return EloRatingResource::collection($ratings);
    }
}
