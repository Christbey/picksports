<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;
use Carbon\Carbon;

class SituationalTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'situational';
    }

    public function collect(): array
    {
        $messages = [];
        $primetimeNetworks = $this->config('primetime_networks', ['NBC', 'ESPN', 'ABC']);

        $primetimeGames = $this->games->filter(function ($game) use ($primetimeNetworks) {
            $networks = $game->broadcast_networks ?? [];
            if (is_string($networks)) {
                $networks = json_decode($networks, true) ?? [];
            }

            if ($this->league === 'nfl') {
                $networks = array_map(fn ($network) => $network === 'Prime Video' ? 'Amazon Prime Video' : $network, $networks);
                if (empty($game->game_date) || empty($game->game_time)) {
                    return false;
                }
                try {
                    $date = Carbon::parse($game->game_date)->toDateString();
                    $hour = Carbon::parse($date.' '.$game->game_time, 'UTC')->setTimezone('America/New_York')->hour;
                    if ($hour < 19) {
                        return false;
                    }
                } catch (\Exception) {
                    return false;
                }
            }

            return collect($networks)->intersect($primetimeNetworks)->isNotEmpty();
        });

        if ($primetimeGames->count() >= 3) {
            $primetimeWins = $primetimeGames->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->teamRecord($primetimeGames)} in primetime games";
        }

        $favoriteGames = $this->games->filter(function ($game) {
            if (! $game->relationLoaded('prediction') || ! $game->prediction) {
                return false;
            }
            $spread = $game->prediction->predicted_spread ?? 0;

            return $this->modelTeamMargin($game) !== null && $this->modelTeamMargin($game) > 0;
        });

        if ($favoriteGames->count() >= 3) {
            $favoriteWins = $favoriteGames->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->teamRecord($favoriteGames)} when the Picksports model made them favorites";
        }

        $underdogGames = $this->games->filter(function ($game) {
            if (! $game->relationLoaded('prediction') || ! $game->prediction) {
                return false;
            }
            $spread = $game->prediction->predicted_spread ?? 0;

            return $this->modelTeamMargin($game) !== null && $this->modelTeamMargin($game) < 0;
        });

        if ($underdogGames->count() >= 3) {
            $underdogWins = $underdogGames->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->teamRecord($underdogGames)} when the Picksports model made them underdogs";
        }

        return $messages;
    }
}
