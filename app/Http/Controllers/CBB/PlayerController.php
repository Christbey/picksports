<?php

namespace App\Http\Controllers\CBB;

use App\Http\Controllers\Controller;
use App\Http\Resources\CBB\PlayerResource;
use App\Models\CBB\Player;
use App\Models\CBB\PlayerProp;
use Inertia\Inertia;
use Inertia\Response;

class PlayerController extends Controller
{
    public function __invoke(Player $player): Response
    {
        $player->load(['team', 'activeInjuries.player']);

        $playerProps = PlayerProp::query()
            ->whereHas('game', function ($query) {
                $query->where('status', 'STATUS_SCHEDULED')
                    ->whereDate('game_date', '>=', now());
            })
            ->where(function ($query) use ($player) {
                $query->where('player_id', $player->id)
                    ->orWhere('player_name', 'like', '%'.$player->last_name.'%');
            })
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->orderBy('fetched_at', 'desc')
            ->get()
            ->groupBy('market')
            ->map(fn ($props) => $props->first())
            ->values();

        return Inertia::render('CBB/Player', [
            'player' => (new PlayerResource($player))->resolve(),
            'playerProps' => $playerProps->map(function ($prop) {
                return [
                    'id' => $prop->id,
                    'market' => $this->formatMarketName($prop->market),
                    'line' => (float) $prop->line,
                    'over_price' => $prop->over_price,
                    'under_price' => $prop->under_price,
                    'bookmaker' => $prop->bookmaker,
                    'game' => [
                        'id' => $prop->game->id,
                        'home_team' => $prop->game->homeTeam?->abbreviation,
                        'away_team' => $prop->game->awayTeam?->abbreviation,
                        'date' => $prop->game->game_date,
                        'time' => $prop->game->game_time,
                    ],
                ];
            }),
        ]);
    }

    protected function formatMarketName(string $market): string
    {
        return match ($market) {
            'player_points' => 'Points',
            'player_rebounds' => 'Rebounds',
            'player_assists' => 'Assists',
            'player_threes' => '3-Pointers Made',
            'player_blocks' => 'Blocks',
            'player_steals' => 'Steals',
            'player_points_rebounds_assists' => 'Points + Rebounds + Assists',
            default => str_replace('_', ' ', ucwords($market, '_')),
        };
    }
}
