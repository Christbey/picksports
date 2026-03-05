<?php

namespace App\Http\Controllers\NFL;

use App\Http\Controllers\Controller;
use App\Models\NFL\Player;
use Inertia\Inertia;
use Inertia\Response;

class PlayerController extends Controller
{
    public function __invoke(Player $player): Response
    {
        $player->load(['team', 'activeInjuries.player']);

        return Inertia::render('NFL/Player', [
            'player' => [
                'id' => $player->id,
                'team_id' => $player->team_id,
                'first_name' => $player->first_name,
                'last_name' => $player->last_name,
                'full_name' => $player->full_name,
                'name' => $player->full_name,
                'jersey_number' => $player->jersey_number,
                'position' => $player->position,
                'height' => $player->height,
                'weight' => $player->weight,
                'headshot_url' => $player->headshot_url,
                'active_injuries_count' => $player->activeInjuries->count(),
                'active_injuries' => $player->activeInjuries->map(fn ($injury) => [
                    'id' => $injury->id,
                    'status' => $injury->status,
                    'detail' => $injury->detail,
                    'type' => $injury->type,
                    'return_date' => $injury->return_date?->toDateString(),
                    'source_updated_at' => $injury->source_updated_at?->toIso8601String(),
                ])->values(),
                'team' => $player->team ? [
                    'id' => $player->team->id,
                    'name' => $player->team->name,
                    'display_name' => trim(($player->team->location ? "{$player->team->location} " : '').$player->team->name),
                    'abbreviation' => $player->team->abbreviation,
                ] : null,
            ],
        ]);
    }
}
