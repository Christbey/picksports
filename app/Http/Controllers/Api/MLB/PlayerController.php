<?php

namespace App\Http\Controllers\Api\MLB;

use App\Http\Controllers\Controller;
use App\Http\Resources\MLB\PlayerResource;
use App\Models\MLB\Player;
use App\Models\MLB\Team;

class PlayerController extends Controller
{
    /**
     * Display a listing of MLB players.
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
     * Display the specified MLB player.
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
            ->get();

        return PlayerResource::collection($players);
    }
}
