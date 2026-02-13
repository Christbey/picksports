<?php

namespace App\Http\Controllers\Api\WCBB;

use App\Http\Controllers\Controller;
use App\Http\Resources\WCBB\PlayerResource;
use App\Models\WCBB\Player;
use App\Models\WCBB\Team;

class PlayerController extends Controller
{
    /**
     * Display a listing of WCBB players.
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
     * Display the specified WCBB player.
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
