<?php

namespace App\Http\Controllers\Api\NFL;

use App\Http\Controllers\Controller;
use App\Http\Resources\NFL\PlayerResource;
use App\Models\NFL\Player;
use App\Models\NFL\Team;

class PlayerController extends Controller
{
    /**
     * Display a listing of NFL players.
     */
    public function index()
    {
        $players = Player::query()
            ->with('team')
            ->orderBy('full_name')
            ->paginate(15);

        return PlayerResource::collection($players);
    }

    /**
     * Display the specified NFL player.
     */
    public function show(Player $player)
    {
        $player->load('team');

        return new PlayerResource($player);
    }

    /**
     * Display players for a specific team.
     */
    public function byTeam(Team $team)
    {
        $players = Player::query()
            ->where('team_id', $team->id)
            ->orderBy('full_name')
            ->paginate(15);

        return PlayerResource::collection($players);
    }
}
