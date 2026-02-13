<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;

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

            return collect($networks)->intersect($primetimeNetworks)->isNotEmpty();
        });

        if ($primetimeGames->count() >= 3) {
            $primetimeWins = $primetimeGames->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($primetimeWins, $primetimeGames->count())} in primetime games";
        }

        $favoriteGames = $this->games->filter(function ($game) {
            if (! $game->relationLoaded('prediction') || ! $game->prediction) {
                return false;
            }
            $spread = $game->prediction->predicted_spread ?? 0;

            return $this->isHome($game) ? $spread < 0 : $spread > 0;
        });

        if ($favoriteGames->count() >= 3) {
            $favoriteWins = $favoriteGames->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($favoriteWins, $favoriteGames->count())} as favorites";
        }

        $underdogGames = $this->games->filter(function ($game) {
            if (! $game->relationLoaded('prediction') || ! $game->prediction) {
                return false;
            }
            $spread = $game->prediction->predicted_spread ?? 0;

            return $this->isHome($game) ? $spread > 0 : $spread < 0;
        });

        if ($underdogGames->count() >= 3) {
            $underdogWins = $underdogGames->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($underdogWins, $underdogGames->count())} as underdogs";
        }

        return $messages;
    }
}
