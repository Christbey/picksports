<?php

namespace App\Http\Controllers\Api\CFB;

use App\Http\Controllers\Controller;
use App\Http\Resources\CFB\PlayerResource;
use App\Models\CFB\Player;
use App\Models\CFB\Team;

class PlayerController extends Controller
{
    /**
     * Display a listing of CFB players.
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
     * Display the specified CFB player.
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
