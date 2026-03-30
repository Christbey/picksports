<?php

namespace App\Services\ESPN;

use App\DataTransferObjects\ESPN\GameData;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;

class HistoricalBackfillService
{
    /**
     * @return array<int, string>
     */
    public function supportedSports(): array
    {
        return array_keys($this->definitions());
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    public function resolveDateRange(
        string $sport,
        ?int $season = null,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        $definition = $this->definition($sport);

        if ($season !== null && ($fromDate !== null || $toDate !== null)) {
            throw new InvalidArgumentException('Use either --season or --from-date/--to-date, not both.');
        }

        if ($season !== null) {
            return $this->seasonDateRange(
                $season,
                (int) $definition['season_start_month'],
                (int) $definition['season_end_month']
            );
        }

        if ($fromDate === null && $toDate === null) {
            throw new InvalidArgumentException('Provide --season or --from-date/--to-date.');
        }

        $start = Carbon::parse($fromDate ?? $toDate)->startOfDay();
        $end = Carbon::parse($toDate ?? $fromDate)->endOfDay();

        if ($start->gt($end)) {
            throw new InvalidArgumentException('The start date must be on or before the end date.');
        }

        return ['start' => $start, 'end' => $end];
    }

    public function runScoreboardSync(
        string $sport,
        Carbon $startDate,
        Carbon $endDate,
        bool $sync,
        ?callable $progress = null
    ): int {
        $definition = $this->definition($sport);
        $jobClass = $definition['scoreboard_job'];
        $days = 0;
        $cursor = $startDate->copy();

        while ($cursor->lte($endDate)) {
            $job = new $jobClass($cursor->format('Ymd'));

            if ($sync) {
                $job->handle();
            } else {
                Bus::dispatch($job);
            }

            $days++;
            $progress && $progress($cursor->copy(), $days);
            $cursor->addDay();
        }

        return $days;
    }

    /**
     * @return Collection<int, Model>
     */
    public function pendingDetailGames(
        string $sport,
        Carbon $startDate,
        Carbon $endDate,
        bool $missingOnly = true,
        int $limit = 0
    ): Collection {
        $definition = $this->definition($sport);
        $gameModel = $definition['game_model'];

        $query = $gameModel::query()
            ->whereDate('game_date', '>=', $startDate->toDateString())
            ->whereDate('game_date', '<=', $endDate->toDateString())
            ->whereIn('status', GameData::finalStatuses())
            ->whereNotNull('espn_event_id')
            ->orderBy('game_date')
            ->orderBy('id');

        if ($missingOnly) {
            $query->where(function ($builder): void {
                $builder
                    ->doesntHave('plays')
                    ->orDoesntHave('playerStats')
                    ->orDoesntHave('teamStats');
            });
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        /** @var Collection<int, Model> $games */
        $games = $query->get();

        return $games;
    }

    /**
     * @param  Collection<int, Model>  $games
     */
    public function runDetailSyncForGames(
        string $sport,
        Collection $games,
        bool $sync,
        ?callable $progress = null
    ): int {
        $definition = $this->definition($sport);
        $jobClass = $definition['detail_job'];

        if ($jobClass === null) {
            throw new InvalidArgumentException(sprintf(
                '%s does not have a shared ESPN game-details backfill job configured yet.',
                $definition['label']
            ));
        }
        $processed = 0;

        foreach ($games as $game) {
            $eventId = trim((string) ($game->espn_event_id ?? ''));
            if ($eventId === '') {
                continue;
            }

            $job = new $jobClass($eventId);

            if ($sync) {
                $job->handle();
            } else {
                Bus::dispatch($job);
            }

            $processed++;
            $progress && $progress($game, $processed);
        }

        return $processed;
    }

    /**
     * @return array{label: string, game_model: class-string<Model>, scoreboard_job: class-string, detail_job: class-string|null, season_start_month: int, season_end_month: int}
     */
    public function definition(string $sport): array
    {
        $normalized = strtolower(trim($sport));
        $definitions = $this->definitions();

        if (! isset($definitions[$normalized])) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported sport "%s". Supported sports: %s',
                $sport,
                implode(', ', array_keys($definitions))
            ));
        }

        /** @var array{label: string, game_model: class-string<Model>, scoreboard_job: class-string, detail_job: class-string|null, season_start_month: int, season_end_month: int} $definition */
        $definition = $definitions[$normalized];

        return $definition;
    }

    /**
     * @return array<string, array{label: string, game_model: class-string<Model>, scoreboard_job: class-string, detail_job: class-string|null, season_start_month: int, season_end_month: int}>
     */
    protected function definitions(): array
    {
        /** @var array<string, array{label: string, game_model: class-string<Model>, scoreboard_job: class-string, detail_job: class-string|null, season_start_month: int, season_end_month: int}> $definitions */
        $definitions = config('espn_backfill.sports', []);

        return $definitions;
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    protected function seasonDateRange(int $season, int $startMonth, int $endMonth): array
    {
        $crossesYearBoundary = $startMonth > $endMonth;
        $startYear = $crossesYearBoundary ? $season - 1 : $season;

        return [
            'start' => Carbon::create($startYear, $startMonth, 1)->startOfMonth(),
            'end' => Carbon::create($season, $endMonth, 1)->endOfMonth(),
        ];
    }
}
