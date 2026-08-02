<?php

namespace App\Support\MLB;

use App\Models\MLB\Game;
use Illuminate\Support\Carbon;

final class MlbGameStart
{
    public static function for(Game $game): ?Carbon
    {
        if ($game->game_date === null || ! is_string($game->game_time) || trim($game->game_time) === '') {
            return null;
        }

        return Carbon::parse(
            $game->game_date->toDateString().' '.$game->game_time,
            (string) config('app.timezone', 'UTC'),
        );
    }
}
