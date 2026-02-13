<?php

namespace App\Http\Controllers\Api\CFB;

use App\Http\Controllers\Controller;
use App\Http\Resources\CFB\GameResource;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use Illuminate\Http\Request;

class GameController extends Controller
{
    /**
     * Display a listing of CFB games.
     */
    public function index()
    {
        $games = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->orderByDesc('start_date')
            ->paginate(15);

        return GameResource::collection($games);
    }

    /**
     * Display the specified CFB game.
     */
    public function show(Game $game)
    {
        $game->load(['homeTeam', 'awayTeam']);

        return new GameResource($game);
    }

    /**
     * Display games for a specific team.
     */
    public function byTeam(Team $team)
    {
        $games = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where(function ($query) use ($team) {
                $query->where('home_team_id', $team->id)
                    ->orWhere('away_team_id', $team->id);
            })
            ->orderByDesc('start_date')
            ->paginate(15);

        return GameResource::collection($games);
    }

    /**
     * Display games for a specific season.
     */
    public function bySeason(Request $request)
    {
        $season = $request->input('season');

        $games = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where('season', $season)
            ->orderByDesc('start_date')
            ->paginate(15);

        return GameResource::collection($games);
    }

    /**
     * Display games for a specific week.
     */
    public function byWeek(Request $request)
    {
        $season = $request->input('season');
        $week = $request->input('week');

        $games = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where('season', $season)
            ->where('week', $week)
            ->orderByDesc('start_date')
            ->paginate(15);

        return GameResource::collection($games);
    }
}
