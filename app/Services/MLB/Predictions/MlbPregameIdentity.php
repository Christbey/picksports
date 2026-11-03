<?php

namespace App\Services\MLB\Predictions;

use App\Models\MLB\Game;
use App\Models\SportEvent;
use App\Support\MLB\MlbGameStart;

class MlbPregameIdentity
{
    public function matches(Game $game, ?SportEvent $event = null): bool
    {
        $event ??= $game->sportEvent;
        $start = MlbGameStart::for($game);

        return $event !== null && $start !== null && $event->starts_at !== null
            && $start->equalTo($event->starts_at);
    }
}
