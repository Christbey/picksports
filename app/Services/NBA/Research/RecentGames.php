<?php

namespace App\Services\NBA\Research;

use App\Models\NBA\Game;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class RecentGames
{
    public function before(Game $game, int $teamId, CarbonInterface $cutoff): Collection
    {
        return Game::query()->with('sportEvent')->where('season', $game->season)->where('season_type', $game->season_type)
            ->where('id', '!=', $game->id)->whereDate('game_date', '<=', $cutoff->copy()->utc()->addDay()->toDateString())
            ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->orderByDesc('game_date')->orderByDesc('game_time')->limit(40)->get()
            ->filter(function (Game $row) use ($cutoff): bool {
                $start = $row->sportEvent?->starts_at ?? app(SportsDateWindowService::class)->gameDateTimeUtc($row->game_date, $row->game_time);

                return $start && $start->lt($cutoff) && $start->lt(now());
            })->values();
    }
}
