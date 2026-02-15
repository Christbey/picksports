<?php

namespace App\Http\Controllers\Api\Sports;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class AbstractGameController extends Controller
{
    /**
     * Get the Game model class for this sport
     */
    abstract protected function getGameModel(): string;

    /**
     * Get the Team model class for this sport
     */
    abstract protected function getTeamModel(): string;

    /**
     * Get the GameResource class for this sport
     */
    abstract protected function getGameResource(): string;

    /**
     * Display a listing of games
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $gameModel = $this->getGameModel();
        $resourceClass = $this->getGameResource();

        $games = $gameModel::query()
            ->with(['homeTeam', 'awayTeam', 'prediction'])
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->season, fn ($q, $season) => $q->where('season', $season))
            ->orderByDesc('game_date')
            ->paginate($request->per_page ?? 15);

        return $resourceClass::collection($games);
    }

    /**
     * Display the specified game
     */
    public function show(int $game): JsonResource
    {
        $gameModel = $this->getGameModel();
        $resourceClass = $this->getGameResource();

        $game = $gameModel::query()->with(['homeTeam', 'awayTeam', 'prediction'])->findOrFail($game);

        return new $resourceClass($game);
    }

    /**
     * Display games for a specific team
     */
    public function byTeam(int $team, Request $request): AnonymousResourceCollection
    {
        $gameModel = $this->getGameModel();
        $resourceClass = $this->getGameResource();

        $games = $gameModel::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where(function ($query) use ($team) {
                $query->where('home_team_id', $team)
                    ->orWhere('away_team_id', $team);
            })
            ->orderByDesc('game_date')
            ->paginate($request->per_page ?? 15);

        return $resourceClass::collection($games);
    }

    /**
     * Display games for a specific season
     */
    public function bySeason(int $season, Request $request): AnonymousResourceCollection
    {
        $gameModel = $this->getGameModel();
        $resourceClass = $this->getGameResource();

        $games = $gameModel::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where('season', $season)
            ->orderByDesc('game_date')
            ->paginate($request->per_page ?? 50);

        return $resourceClass::collection($games);
    }

    /**
     * Display games for a specific week
     */
    public function byWeek(int $season, int $week, Request $request): AnonymousResourceCollection
    {
        $gameModel = $this->getGameModel();
        $resourceClass = $this->getGameResource();

        $games = $gameModel::query()
            ->with(['homeTeam', 'awayTeam', 'prediction'])
            ->where('season', $season)
            ->where('week', $week)
            ->oldest('game_date')
            ->get();

        return $resourceClass::collection($games);
    }
}
