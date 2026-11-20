<?php

namespace App\Services\Api\V2;

use App\Models\CanonicalPrediction;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CanonicalSportPredictionQuery
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    private const SUPPORTED_SPORTS = ['cbb', 'cfb', 'mlb', 'nba', 'nfl', 'wcbb', 'wnba'];

    public function __construct(private readonly SportGameQuery $games) {}

    public function supports(SportContext $context): bool
    {
        return in_array($context->slug, self::SUPPORTED_SPORTS, true)
            && (bool) config("prediction_lifecycle.canonical_reads.{$context->slug}", false);
    }

    /** @param array<string, mixed> $filters */
    public function paginate(
        SportContext $context,
        array $filters = [],
        ?Authenticatable $user = null,
    ): LengthAwarePaginator {
        $this->requireSupport($context);

        return $this->queryForSport($context->slug, $filters)
            ->paginate(min((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));
    }

    public function find(
        SportContext $context,
        int|string $prediction,
        ?Authenticatable $user = null,
    ): CanonicalPrediction {
        $this->requireSupport($context);

        return $this->queryForSport($context->slug)
            ->where('predictions.public_id', (string) $prediction)
            ->firstOrFail();
    }

    public function findForGame(
        SportContext $context,
        int|string $game,
        ?Authenticatable $user = null,
    ): CanonicalPrediction {
        $this->requireSupport($context);

        $resolvedGame = $this->games->find($context, $game, $user, 'identity');

        return $this->queryForSport($context->slug, ['game_id' => $resolvedGame->getKey()])->firstOrFail();
    }

    /** @return Collection<int, int> */
    public function availableSeasons(
        SportContext $context,
        ?Authenticatable $user = null,
    ): Collection {
        $this->requireSupport($context);
        $gameTable = $this->gameTable($context->slug);

        return $this->queryForSport($context->slug)
            ->reorder()
            ->select("{$gameTable}.season as available_season")
            ->distinct()
            ->orderByDesc('available_season')
            ->pluck('available_season')
            ->map(fn (mixed $season): int => (int) $season)
            ->values();
    }

    /** @param array<string, mixed> $filters @return Collection<int, string> */
    public function availableDates(
        SportContext $context,
        array $filters = [],
        ?Authenticatable $user = null,
    ): Collection {
        $this->requireSupport($context);
        $gameTable = $this->gameTable($context->slug);

        return $this->queryForSport($context->slug, $filters)
            ->reorder()
            ->whereNotNull("{$gameTable}.game_date")
            ->select(["{$gameTable}.game_date", "{$gameTable}.game_time"])
            ->orderBy("{$gameTable}.game_date")
            ->orderBy("{$gameTable}.game_time")
            ->toBase()->get()
            ->map(fn (mixed $game): ?string => app(SportsDateWindowService::class)->gameDateForDisplay($game->game_date, $game->game_time))
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    /** @param array<string, mixed> $filters @return Builder<CanonicalPrediction> */
    public function queryForSport(string $sport, array $filters = []): Builder
    {
        $gameTable = $this->gameTable($sport);
        $gameRelation = $sport.'Game';

        return CanonicalPrediction::query()
            ->select('predictions.*')
            ->join('sport_events', 'sport_events.id', '=', 'predictions.sport_event_id')
            ->join($gameTable, "{$gameTable}.sport_event_id", '=', 'sport_events.id')
            ->with([
                'markets',
                "sportEvent.{$gameRelation}.homeTeam",
                "sportEvent.{$gameRelation}.awayTeam",
                'latestEvaluation.eventResult',
                'calculationRun.release',
                'calculationRun.inputSnapshot',
            ])
            ->when($sport === 'cfb', fn (Builder $query) => $query->with(
                'sportEvent.cfbGame.latestLiveSnapshot:cfb_live_prediction_snapshots.id,cfb_live_prediction_snapshots.game_id,status,projection,observed_at'
            ))
            ->when($sport === 'wcbb', fn (Builder $query) => $query->with('trackedWcbbSpread'))
            ->where('predictions.sport', $sport)
            ->where('predictions.phase', 'pregame')
            ->where('predictions.publication_state', 'published')
            ->whereNotNull('predictions.published_at')
            ->whereColumn('predictions.published_at', '<=', 'sport_events.starts_at')
            ->whereNotNull('predictions.calculation_run_id')
            ->whereHas('calculationRun', function (Builder $query): void {
                $query->where('status', 'succeeded')
                    ->whereHas('inputSnapshot', fn (Builder $query): Builder => $query
                        ->where('phase', 'pregame')
                        ->where('pregame_safety_status', 'verified'))
                    ->whereHas('release', fn (Builder $query): Builder => $query
                        ->whereIn('status', ['approved', 'retired']));
            })
            ->when($filters['season'] ?? null, fn (Builder $query, int $season): Builder => $query->where("{$gameTable}.season", $season))
            ->when($filters['season_type'] ?? null, fn (Builder $query, string $seasonType): Builder => $query->where("{$gameTable}.season_type", $seasonType))
            ->when(array_key_exists('week', $filters), fn (Builder $query): Builder => $query->where("{$gameTable}.week", $filters['week']))
            ->when(array_key_exists('game_id', $filters), fn (Builder $query): Builder => $query->where("{$gameTable}.id", $filters['game_id']))
            ->when($filters['team_id'] ?? null, function (Builder $query, int $teamId) use ($gameTable): Builder {
                return $query->where(function (Builder $query) use ($teamId, $gameTable): void {
                    $query->where("{$gameTable}.home_team_id", $teamId)
                        ->orWhere("{$gameTable}.away_team_id", $teamId);
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where("{$gameTable}.status", $status))
            ->tap(function (Builder $query) use ($filters, $gameTable): void {
                if (isset($filters['from_date'], $filters['to_date'])) {
                    $dates = app(SportsDateWindowService::class);
                    $dates->applyGameDateWindow($query, $dates->forRange($filters['from_date'], $filters['to_date']), "{$gameTable}.game_date");
                } elseif (isset($filters['from_date'])) {
                    $query->whereDate("{$gameTable}.game_date", '>=', $filters['from_date']);
                } elseif (isset($filters['to_date'])) {
                    $query->whereDate("{$gameTable}.game_date", '<=', $filters['to_date']);
                }
            })
            ->when(array_key_exists('has_value', $filters), function (Builder $query) use ($filters): Builder {
                $method = $filters['has_value'] ? 'whereHas' : 'whereDoesntHave';

                return $query->{$method}('markets', fn (Builder $query): Builder => $query
                    ->where('market_type', 'spread')
                    ->where('selection', 'home')
                    ->whereNotNull('projected_line'));
            })
            ->orderByDesc('predictions.published_at')
            ->orderByDesc('predictions.id');
    }

    private function requireSupport(SportContext $context): void
    {
        if (! $this->supports($context)) {
            throw new \LogicException('Canonical prediction reads are not enabled for this sport context.');
        }
    }

    private function gameTable(string $sport): string
    {
        if (! in_array($sport, self::SUPPORTED_SPORTS, true)) {
            throw new \InvalidArgumentException("Canonical prediction reads do not support {$sport}.");
        }

        return "{$sport}_games";
    }
}
