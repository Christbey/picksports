<?php

namespace App\Http\Controllers\NBA;

use App\Http\Controllers\Controller;
use App\Http\Resources\NBA\PlayerResource;
use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\PlayerProp;
use Inertia\Inertia;
use Inertia\Response;

class PlayerController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Player $player): Response
    {
        $player->load('team');

        // Get upcoming player props for this player
        $playerProps = PlayerProp::query()
            ->where('sport', 'basketball_nba')
            ->where('gameable_type', Game::class)
            ->where(function ($q) use ($player) {
                $q->where('player_id', $player->id)
                    ->orWhere('player_name', 'like', '%'.$player->last_name.'%');
            })
            ->whereHas('gameable', function ($q) {
                $q->where('status', 'STATUS_SCHEDULED')
                    ->whereDate('game_date', '>=', now());
            })
            ->with(['gameable.homeTeam', 'gameable.awayTeam'])
            ->orderBy('fetched_at', 'desc')
            ->get()
            ->groupBy('market')
            ->map(function ($props) {
                // For each market, return the most recent prop
                return $props->first();
            })
            ->values();

        return Inertia::render('NBA/Player', [
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
                        'id' => $prop->gameable->id,
                        'home_team' => $prop->gameable->homeTeam?->abbreviation,
                        'away_team' => $prop->gameable->awayTeam?->abbreviation,
                        'date' => $prop->gameable->game_date,
                        'time' => $prop->gameable->game_time,
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
