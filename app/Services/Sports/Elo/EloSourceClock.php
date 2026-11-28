<?php

namespace App\Services\Sports\Elo;

use App\Casts\UtcEloDateTime;
use App\Models\MLB\Game;
use App\Support\MLB\MlbGameStart;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class EloSourceClock
{
    public const MLB_POLICY = ['version' => MlbGameStart::TIMING_POLICY, 'identity' => 'all_six_observation_fields_and_current_context', 'legacy_unknown' => 'refuse', 'history_calendar' => 'retained_source_game_date'];

    public static function observed(Model $game): CarbonImmutable
    {
        if ($game instanceof Game) {
            $observed = MlbGameStart::for($game);
            if ($observed === null) {
                throw new InvalidArgumentException('Guarded MLB chronology requires a current verified start observation.');
            }

            return CarbonImmutable::instance($observed)->utc();
        }

        return (new UtcEloDateTime)->observed($game->getRawOriginal('game_date'), $game->getRawOriginal('game_time'));
    }
}
