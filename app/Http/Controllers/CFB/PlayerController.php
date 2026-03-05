<?php

namespace App\Http\Controllers\CFB;

use App\Http\Controllers\Controller;
use App\Models\CFB\Player;
use Inertia\Inertia;
use Inertia\Response;

class PlayerController extends Controller
{
    public function __invoke(Player $player): Response
    {
        $player->load(['team']);

        return Inertia::render('CFB/Player', [
            'player' => [
                'id' => $player->id,
                'team_id' => $player->team_id,
                'first_name' => null,
                'last_name' => null,
                'full_name' => $player->display_name ?: $player->name,
                'name' => $player->display_name ?: $player->name,
                'jersey_number' => $player->jersey,
                'position' => $player->position,
                'height' => $player->height,
                'weight' => $player->weight,
                'headshot_url' => $player->headshot,
                'team' => $player->team ? [
                    'id' => $player->team->id,
                    'name' => trim("{$player->team->school} {$player->team->mascot}"),
                    'display_name' => trim("{$player->team->school} {$player->team->mascot}"),
                    'abbreviation' => $player->team->abbreviation,
                ] : null,
            ],
        ]);
    }
}
