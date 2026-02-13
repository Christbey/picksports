<?php

namespace App\Http\Controllers\Api\CFB;

use App\Http\Controllers\Controller;
use App\Http\Resources\CFB\FpiRatingResource;
use App\Models\CFB\FpiRating;
use App\Models\CFB\Team;

class FpiRatingController extends Controller
{
    /**
     * Display a listing of CFB FPI ratings.
     */
    public function index()
    {
        $ratings = FpiRating::query()
            ->with(['team'])
            ->orderByDesc('year')
            ->paginate(15);

        return FpiRatingResource::collection($ratings);
    }

    /**
     * Display the specified CFB FPI rating.
     */
    public function show(FpiRating $fpiRating)
    {
        $fpiRating->load(['team']);

        return new FpiRatingResource($fpiRating);
    }

    /**
     * Display FPI ratings for a specific team.
     */
    public function byTeam(Team $team)
    {
        $ratings = FpiRating::query()
            ->where('team_id', $team->id)
            ->orderByDesc('year')
            ->paginate(15);

        return FpiRatingResource::collection($ratings);
    }
}
