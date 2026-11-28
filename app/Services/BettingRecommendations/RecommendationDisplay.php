<?php

namespace App\Services\BettingRecommendations;

use App\Models\MLB\Game;
use App\Services\Sports\SportsDateWindowService;
use App\Support\MLB\MlbGameStart;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class RecommendationDisplay
{
    public static function edgeUnit(array $selection): ?string
    {
        return $selection['edge_unit'] ?? match ($selection['type'] ?? null) {
            'spread', 'total' => 'points',
            'moneyline' => 'percentage_points',
            default => null,
        };
    }

    public static function edge(array $selection): string
    {
        if (! is_numeric($selection['edge'] ?? null)) {
            return 'Unavailable';
        }

        $unit = match (self::edgeUnit($selection)) {
            'points' => 'points',
            'percentage_points' => 'percentage points',
            'stat_units' => 'stat units',
            default => null,
        };

        return $unit === null ? 'Unavailable' : number_format((float) $selection['edge'], 1).' '.$unit;
    }

    public static function price(mixed $price): string
    {
        if (! is_numeric($price)) {
            return 'Unavailable';
        }

        return ((float) $price > 0 ? '+' : '').number_format((float) $price, 0, '.', '');
    }

    public static function kickoff(Model $game, ?CarbonInterface $startsAt = null): ?CarbonImmutable
    {
        $dates = app(SportsDateWindowService::class);
        if ($game instanceof Game) {
            return MlbGameStart::for($game)?->toImmutable()->setTimezone($dates->timezone());
        }
        if ($startsAt !== null) {
            return CarbonImmutable::instance($startsAt)->setTimezone($dates->timezone());
        }

        $hasTime = (is_string($game->game_time) && preg_match('/^\d{1,2}:\d{2}/', $game->game_time) === 1)
            || ($game->game_date instanceof CarbonInterface && $game->game_date->format('H:i:s') !== '00:00:00');

        return $hasTime ? $dates->gameDateTimeUtc($game->game_date, $game->game_time)?->setTimezone($dates->timezone()) : null;
    }
}
