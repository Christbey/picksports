<?php

namespace App\Http\Controllers\Api\CBB;

use App\Http\Controllers\Controller;
use App\Http\Resources\CBB\PlayerResource;
use App\Models\CBB\Player;
use App\Models\CBB\Team;

class PlayerController extends Controller
{
    /**
     * Display a listing of CBB players.
     */
    public function index()
    {
        $players = Player::query()
            ->with('team')
            ->orderBy('display_name')
            ->paginate(15);

        return PlayerResource::collection($players);
    }

    /**
     * Display the specified CBB player.
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
            ->orderBy('display_name')
            ->paginate(15);

        return PlayerResource::collection($players);
    }
}
