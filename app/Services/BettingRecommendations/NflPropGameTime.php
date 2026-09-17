<?php

namespace App\Services\BettingRecommendations;

use App\Models\NFL\Game;
use App\Services\Sports\SportsDateWindowService;

class NflPropGameTime
{
    public function __construct(private SportsDateWindowService $dates) {}

    /** The board and its cards share one explicit, DST-aware kickoff contract. */
    public function forGame(Game $game): array
    {
        $hasTime = (is_string($game->game_time) && preg_match('/^\d{1,2}:\d{2}/', $game->game_time) === 1)
            || ($game->game_date && $game->game_date->format('H:i:s') !== '00:00:00');
        $kickoff = $hasTime ? $this->dates->gameDateTimeUtc($game->game_date, $game->game_time) : null;
        $local = $kickoff?->setTimezone($this->dates->timezone());

        return [
            'date' => $local?->toDateString() ?? $game->game_date?->toDateString(),
            'starts_at' => $kickoff?->toIso8601String(),
            'timezone' => $this->dates->timezone(),
            'time_label' => $local?->format('g:i A T') ?? 'Time TBD',
            'kickoff_label' => $local?->format('D, M j, g:i A T') ?? 'Time TBD',
        ];
    }
}
