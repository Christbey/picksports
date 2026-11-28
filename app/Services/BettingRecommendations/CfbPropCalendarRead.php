<?php

namespace App\Services\BettingRecommendations;

use App\Services\Sports\CfbGameCalendarRead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Compatibility adapter for prop callers; the bounded calendar core also serves generic CFB reads. */
final class CfbPropCalendarRead
{
    public function onDate(Builder $games, string $date): Builder
    {
        return app(CfbGameCalendarRead::class)->onDate($games, $date);
    }

    public function datesFor(Builder $games): Collection
    {
        return app(CfbGameCalendarRead::class)->datesFor($games);
    }
}
