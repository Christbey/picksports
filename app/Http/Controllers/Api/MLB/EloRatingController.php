<?php

namespace App\Http\Controllers\Api\MLB;

use App\Http\Controllers\Controller;
use App\Http\Resources\MLB\EloRatingResource;
use App\Models\MLB\EloRating;
use App\Models\MLB\Team;
use Illuminate\Http\Request;

class EloRatingController extends Controller
{
    /**
     * Display a listing of MLB Elo ratings.
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
     * Display the specified MLB Elo rating.
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
