<?php

namespace App\Services\Api\V2;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

class CfbPredictionBoardPeriod
{
    /**
     * @param  Collection<int, int>  $seasons
     * @return array{season: ?int, season_type: ?string, week: ?int}
     */
    public function resolve(
        SportContext $context,
        SportPredictionQuery|CanonicalSportPredictionQuery $query,
        Collection $seasons,
        ?Authenticatable $user,
    ): array {
        $today = CarbonImmutable::now('America/Chicago');
        $footballSeason = $today->month <= 2 ? $today->year - 1 : $today->year;
        $season = $seasons->containsStrict($footballSeason) ? $footballSeason : $seasons->max();
        $period = ['season' => $season, 'season_type' => null, 'week' => null];

        if ($season === null) {
            return $period;
        }

        $dates = $query->availableDates($context, ['season' => $season], $user)->sort()->values();
        $date = $today->toDateString();
        $monday = $today->startOfWeek(CarbonInterface::MONDAY)->toDateString();
        $sunday = $today->endOfWeek(CarbonInterface::SUNDAY)->toDateString();
        $slate = $dates->first(fn (string $day): bool => $day >= $date && $day <= $sunday)
            ?? $dates->last(fn (string $day): bool => $day >= $monday && $day < $date)
            ?? $dates->first(fn (string $day): bool => $day >= $date)
            ?? $dates->last();

        if ($slate === null) {
            return $period;
        }

        $filters = ['season' => $season, 'from_date' => $slate, 'to_date' => $slate];
        $game = $query instanceof CanonicalSportPredictionQuery
            ? $query->queryForSport($context->slug, $filters)->setEagerLoads([])
                ->select(['cfb_games.season_type', 'cfb_games.week'])->first()
            : $query->query($context, $filters, $user)->setEagerLoads([])
                ->with('game:id,season_type,week')->first(['game_id'])?->game;

        return [
            'season' => $season,
            'season_type' => isset($game->season_type) ? (string) $game->season_type : null,
            'week' => isset($game->week) ? (int) $game->week : null,
        ];
    }
}
