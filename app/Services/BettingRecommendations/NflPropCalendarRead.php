<?php

namespace App\Services\BettingRecommendations;

use App\Services\Sports\SportsDateWindowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use LogicException;

/** Read-only calendar inventory; not a source-observation or model-approval receipt. */
final class NflPropCalendarRead
{
    private const CHUNK_SIZE = 8;

    public function __construct(
        private readonly NflPropGameTime $times,
        private readonly SportsDateWindowService $dates,
    ) {}

    /** @return Collection<int, string> */
    public function datesFor(Builder $games): Collection
    {
        $dates = [];
        foreach ($this->clockRows(clone $games) as $game) {
            $date = $this->times->forGame($game)['date'];
            if (is_string($date)) {
                $dates[$date] = $date;
            }
        }
        ksort($dates);

        return collect(array_values($dates));
    }

    public function onDate(Builder $games, string $date): Builder
    {
        $window = $this->dates->forDate($date);
        $model = $games->getModel();
        $dateColumn = $model->qualifyColumn('game_date');
        $candidates = $model->newQuery()
            ->whereDate($dateColumn, '>=', min($window->localStartDate(), $window->utcStart->toDateString()))
            ->whereDate($dateColumn, '<=', max($window->localEndDate(), $window->utcEnd->toDateString()));
        $ids = [];
        foreach ($this->clockRows($candidates) as $game) {
            if ($this->times->forGame($game)['date'] === $window->localStartDate()) {
                $ids[] = $game->getRawOriginal($model->getKeyName());
            }
        }

        return $games->whereIn($model->getQualifiedKeyName(), $ids);
    }

    /** SQL projection prevents large bodies from reaching model JSON casts; chunks bound simultaneous bodies. */
    private function clockRows(Builder $games): LazyCollection
    {
        $model = $games->getModel();
        $grammar = $games->getConnection()->getQueryGrammar();
        $body = $grammar->wrap($model->qualifyColumn('odds_data'));
        $bytes = match ($games->getConnection()->getDriverName()) {
            'mysql' => "OCTET_LENGTH({$body})",
            'sqlite' => "LENGTH(CAST({$body} AS BLOB))",
            default => throw new LogicException('Unsupported NFL calendar read connection.'),
        };

        return $games->reorder()->select(array_map($model->qualifyColumn(...), ['id', 'game_date', 'game_time', 'nflverse_game_id']))
            ->selectRaw("CASE WHEN {$bytes} <= ? THEN {$body} ELSE NULL END AS odds_data", [NflPropGameTime::CLOCK_METADATA_BYTES])
            ->selectRaw("CASE WHEN {$bytes} > ? THEN 1 ELSE 0 END AS read_clock_metadata_unavailable", [NflPropGameTime::CLOCK_METADATA_BYTES])
            ->lazyById(self::CHUNK_SIZE, $model->getQualifiedKeyName(), $model->getKeyName());
    }
}
