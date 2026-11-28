<?php

namespace App\Services\Sports;

use App\Models\CFB\Game;
use App\Support\CFB\CfbGameStart;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use LengthException;
use LogicException;

/** Bounded CFB calendar inventory and explicitly qualified instant selection; unknown carriers never become instants. */
final class CfbGameCalendarRead
{
    private const CHUNK_SIZE = 8;

    private const MAX_DAY_CANDIDATES = 1000;

    private const MAX_DAY_IDS = 1000;

    private const MAX_DATE_CANDIDATES = 10000;

    private const MAX_DATES = 1000;

    public function onDate(Builder $games, string $date): Builder
    {
        $start = CarbonImmutable::parse($date, 'America/Chicago')->startOfDay()->utc();
        $end = $start->setTimezone('America/Chicago')->addDay()->utc();
        $model = $games->getModel();
        $candidates = $model->newQuery()->useWritePdo()
            ->whereDate($model->qualifyColumn('game_date'), '>=', min($date, $start->toDateString()))
            ->whereDate($model->qualifyColumn('game_date'), '<=', max($date, $end->toDateString()));
        $ids = [];
        $count = 0;
        foreach ($this->clockRows($candidates) as $game) {
            if (++$count > self::MAX_DAY_CANDIDATES) {
                throw new LengthException('CFB calendar candidate capacity exceeded; no partial inventory was returned.');
            }
            if ($this->date($game) === $date) {
                if (count($ids) >= self::MAX_DAY_IDS) {
                    throw new LengthException('CFB calendar ID capacity exceeded; no partial inventory was returned.');
                }
                $ids[] = $game->getKey();
            }
        }

        return $games->whereIn($model->getQualifiedKeyName(), $ids);
    }

    /** Generic calendar ranges preserve unknown raw dates and qualify known instants precisely. */
    public function inWindow(Builder $games, DateWindow $window): Builder
    {
        $model = $games->getModel();
        // This method also runs inside whereHas callbacks. Never execute a copied
        // correlated builder outside its parent query. Qualify a bounded independent
        // date-window superset, then retain every caller predicate on $games below.
        $candidates = $model->newQuery()->useWritePdo()
            ->whereDate($model->qualifyColumn('game_date'), '>=', min($window->localStartDate(), $window->utcStart->toDateString()))
            ->whereDate($model->qualifyColumn('game_date'), '<=', max($window->localEndDate(), $window->utcEnd->toDateString()));

        if ($candidates->getConnection() !== $games->getConnection()) {
            throw new LogicException('CFB calendar candidate connection must match its caller.');
        }

        return $this->matchingIds($games, $candidates, function (Game $game) use ($window): bool {
            $known = CfbGameStart::for($game);
            if ($known !== null) {
                return $known->betweenIncluded($window->utcStart, $window->utcEnd);
            }
            $raw = $this->rawDate($game);

            return $raw !== null && $raw >= $window->localStartDate() && $raw <= $window->localEndDate();
        });
    }

    /** Exact instant comparisons never admit unknown date/time carriers. */
    public function before(Builder $games, CarbonImmutable $cutoff): Builder
    {
        $candidates = (clone $games)->useWritePdo()
            ->whereDate($games->getModel()->qualifyColumn('game_date'), '<=', $cutoff->utc()->toDateString());

        return $this->matchingIds($games, $candidates, fn (Game $game): bool => ($known = CfbGameStart::for($game)) !== null && $known->lt($cutoff));
    }

    /** Bound recovery candidates before provider IO; unknown carriers cannot establish horizon exclusion. */
    public function metadataCandidates(Builder $games, CarbonImmutable $now, CarbonImmutable $end): Builder
    {
        return $this->matchingIds($games, (clone $games)->useWritePdo(), function (Game $game) use ($now, $end): bool {
            $known = CfbGameStart::for($game);

            return $known === null || ($known->gt($now) && $known->lte($end));
        });
    }

    private function matchingIds(Builder $games, Builder $candidates, callable $matches): Builder
    {
        $base = $games->getQuery();
        if ($base->limit !== null || $base->offset !== null || $base->groups !== null || $base->unions !== null) {
            throw new LogicException('CFB clock filtering requires an unpaginated, ungrouped game candidate query.');
        }
        $ids = [];
        $count = 0;
        foreach ($this->clockRows($candidates) as $game) {
            if (++$count > self::MAX_DATE_CANDIDATES) {
                throw new LengthException('CFB clock candidate capacity exceeded; no partial result was returned.');
            }
            if ($matches($game)) {
                if (count($ids) >= self::MAX_DAY_IDS) {
                    throw new LengthException('CFB clock ID capacity exceeded; no partial result was returned.');
                }
                $ids[] = $game->getKey();
            }
        }

        return $games->whereIn($games->getModel()->getQualifiedKeyName(), $ids);
    }

    public function datesFor(Builder $games): Collection
    {
        $dates = [];
        $count = 0;
        foreach ($this->clockRows(clone $games) as $game) {
            if (++$count > self::MAX_DATE_CANDIDATES) {
                throw new LengthException('CFB available-date candidate capacity exceeded; no partial inventory was returned.');
            }
            $date = $this->date($game);
            if ($date === null) {
                continue;
            }
            if (! isset($dates[$date]) && count($dates) >= self::MAX_DATES) {
                throw new LengthException('CFB available-date capacity exceeded; no partial inventory was returned.');
            }
            $known = CfbGameStart::for($game) !== null;
            if (! isset($dates[$date]) || ! $known) {
                $dates[$date] = ['value' => $date, 'label' => ($known ? '' : 'Unverified date: ').CarbonImmutable::parse($date)->format('l, F j, Y'),
                    'clock_status' => CfbGameStart::status($game)];
            }
        }
        ksort($dates);

        return collect(array_values($dates));
    }

    private function date(Game $game): ?string
    {
        $known = CfbGameStart::for($game);
        if ($known !== null) {
            return $known->setTimezone('America/Chicago')->toDateString();
        }

        return $this->rawDate($game);
    }

    private function rawDate(Game $game): ?string
    {
        $date = substr((string) $game->getRawOriginal('game_date'), 0, 10);

        return preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $date, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $date : null;
    }

    public function withBoundedObservation(Builder $games): Builder
    {
        $grammar = $games->getConnection()->getQueryGrammar();
        $body = $grammar->wrap($games->getModel()->qualifyColumn('kickoff_observation'));
        $bytes = match ($games->getConnection()->getDriverName()) {
            'mysql' => "OCTET_LENGTH($body)",
            'sqlite' => "LENGTH(CAST($body AS BLOB))",
            default => throw new LogicException('Unsupported CFB calendar connection.'),
        };

        return $games->selectRaw("CASE WHEN $bytes <= ? THEN $body ELSE NULL END AS kickoff_observation", [1024])
            ->selectRaw("CASE WHEN $bytes > ? THEN 1 ELSE 0 END AS read_clock_metadata_unavailable", [1024]);
    }

    private function clockRows(Builder $games): LazyCollection
    {
        $model = $games->getModel();
        $grammar = $games->getConnection()->getQueryGrammar();
        $games = $games->setEagerLoads([])->reorder()->select([$model->getQualifiedKeyName()]);
        foreach (['espn_event_id' => 64, 'game_date' => 32, 'game_time' => 32] as $field => $limit) {
            $column = $grammar->wrap($model->qualifyColumn($field));
            $length = $games->getConnection()->getDriverName() === 'mysql' ? "OCTET_LENGTH($column)" : "LENGTH(CAST($column AS BLOB))";
            $games->selectRaw("CASE WHEN $length <= ? THEN $column ELSE NULL END AS ".$grammar->wrap($field), [$limit]);
        }

        return $this->withBoundedObservation($games)
            ->lazyById(self::CHUNK_SIZE, $model->getQualifiedKeyName(), $model->getKeyName());
    }
}
