<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UpcomingPlayerPropResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'player_id' => $this->player_id,
            'market' => $this->formatMarketName((string) $this->market),
            'line' => (float) $this->line,
            'over_price' => $this->over_price,
            'under_price' => $this->under_price,
            'bookmaker' => $this->bookmaker,
            'fetched_at' => $this->fetched_at?->toIso8601String(),
            'player' => $this->whenLoaded('player', fn () => [
                'id' => $this->player?->id,
                'name' => $this->player?->full_name ?? $this->player?->display_name ?? $this->player?->name,
                'position' => $this->player?->position,
                'team' => $this->player?->team ? [
                    'id' => $this->player->team->id,
                    'abbreviation' => $this->player->team->abbreviation,
                    'name' => $this->player->team->name ?? $this->player->team->school,
                ] : null,
            ]),
            'game' => [
                'id' => $this->game?->id,
                'home_team' => $this->game?->homeTeam?->abbreviation ?? $this->game?->homeTeam?->name,
                'away_team' => $this->game?->awayTeam?->abbreviation ?? $this->game?->awayTeam?->name,
                'date' => $this->game?->game_date?->toDateString() ?? $this->game?->game_date,
                'time' => $this->game?->game_time,
            ],
        ];
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
