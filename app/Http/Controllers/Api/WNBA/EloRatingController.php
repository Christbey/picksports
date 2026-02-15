<?php

namespace App\Http\Controllers\Api\WNBA;

use App\Http\Controllers\Controller;
use App\Http\Resources\WNBA\EloRatingResource;
use App\Models\WNBA\EloRating;
use App\Models\WNBA\Team;
use Illuminate\Http\Request;

class EloRatingController extends Controller
{
    /**
     * Display a listing of WNBA Elo ratings.
     */
    public function index()
    {
        $ratings = EloRating::query()
            ->with(['team'])
            ->orderByDesc('season')
            ->orderByDesc('week')
            ->paginate(15);

        return EloRatingResource::collection($ratings);
    }

    /**
     * Display the specified WNBA Elo rating.
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
            ->orderByDesc('season')
            ->orderByDesc('week')
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
            ->orderByDesc('week')
            ->paginate(15);

        return EloRatingResource::collection($ratings);
    }
}
