<?php

namespace App\Services\Api\V2;

use App\Services\Api\V2\Concerns\BuildsSportQueries;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class SportGameQuery
{
    use BuildsSportQueries;

    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 500;

    /**
     * @param  array{status?: string, season?: int, from_date?: string, to_date?: string, per_page?: int, team_id?: int}  $filters
     */
    public function paginate(
        SportContext $context,
        array $filters = [],
        ?Authenticatable $user = null,
        string $relationProfile = 'summary',
    ): LengthAwarePaginator {
        return $this->query($context, $filters, $user, $relationProfile)
            ->paginate($this->perPage($filters, self::DEFAULT_PER_PAGE, self::MAX_PER_PAGE));
    }

    public function find(
        SportContext $context,
        int|string $game,
        ?Authenticatable $user = null,
        string $relationProfile = 'detail',
    ): Model {
        return $this->query($context, [], $user, $relationProfile)->findOrFail($game);
    }

    /**
     * @param  array{status?: string, season?: int, from_date?: string, to_date?: string, per_page?: int, team_id?: int}  $filters
     * @return Builder<Model>
     */
    public function query(
        SportContext $context,
        array $filters = [],
        ?Authenticatable $user = null,
        string $relationProfile = 'summary',
    ): Builder {
        $gameModel = $this->gameModel($context);

        return $gameModel::query()
            ->with($this->relationsFor($gameModel, $relationProfile))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($filters['season'] ?? null, fn (Builder $query, int $season): Builder => $query->where('season', $season))
            ->tap(fn (Builder $query): Builder => $this->whereGameDateFilters($query, $filters))
            ->when($filters['before_game_at'] ?? null, fn (Builder $query, string $before): Builder => $this->whereBeforeGame($query, $before))
            ->when($filters['exclude_game_id'] ?? null, fn (Builder $query, int $gameId): Builder => $query->whereKeyNot($gameId))
            ->when($filters['team_id'] ?? null, function (Builder $query, int $teamId): Builder {
                return $query->where(function (Builder $teamQuery) use ($teamId): void {
                    $teamQuery->where('home_team_id', $teamId)
                        ->orWhere('away_team_id', $teamId);
                });
            })
            ->when(
                $filters['team_id'] ?? null,
                fn (Builder $query): Builder => $query
                    ->orderByDesc('game_date')
                    ->orderByDesc('game_time')
                    ->orderByDesc('id'),
                fn (Builder $query): Builder => $query
                    ->orderBy('game_date')
                    ->orderBy('game_time')
                    ->orderBy('id'),
            );
    }

    /**
     * @param  array{from_date?: string, to_date?: string}  $filters
     */
    private function whereGameDateFilters(Builder $query, array $filters): Builder
    {
        $fromDate = $filters['from_date'] ?? null;
        $toDate = $filters['to_date'] ?? null;

        if (is_string($fromDate) && is_string($toDate)) {
            $window = app(SportsDateWindowService::class)->forRange($fromDate, $toDate);

            return app(SportsDateWindowService::class)->applyGameDateWindow($query, $window);
        }

        if (is_string($fromDate)) {
            return $query->whereDate('game_date', '>=', $fromDate);
        }

        if (is_string($toDate)) {
            return $query->whereDate('game_date', '<=', $toDate);
        }

        return $query;
    }

    private function whereBeforeGame(Builder $query, string $before): Builder
    {
        $cutoff = Carbon::parse($before)->setTimezone((string) config('app.timezone', 'UTC'));
        $date = $cutoff->toDateString();
        $time = $cutoff->format('H:i:s');

        return $query->where(function (Builder $inner) use ($date, $time): void {
            $inner->whereDate('game_date', '<', $date)
                ->orWhere(function (Builder $sameDate) use ($date, $time): void {
                    $sameDate->whereDate('game_date', $date)
                        ->whereTime('game_time', '<', $time);
                });
        });
    }

    /**
     * @return class-string<Model>
     */
    private function gameModel(SportContext $context): string
    {
        return $this->requireModel($context, 'game', 'Games');
    }

    /**
     * @param  class-string<Model>  $gameModel
     * @return array<int, string>
     */
    private function relationsFor(string $gameModel, string $profile): array
    {
        $relations = match ($profile) {
            'identity' => [],
            'recent' => ['homeTeam', 'awayTeam'],
            'summary' => ['homeTeam', 'awayTeam', 'prediction'],
            'page' => [
                'homeTeam',
                'awayTeam',
                'prediction',
                'homeStartingPitcherForecast',
                'awayStartingPitcherForecast',
            ],
            default => [
                'homeTeam',
                'awayTeam',
                'prediction',
                'probableHomePitcher',
                'probableAwayPitcher',
                'actualHomePitcher',
                'actualAwayPitcher',
                'projectedHomePitcher',
                'projectedAwayPitcher',
                'homeStartingPitcherForecast.predictedPitcher',
                'homeStartingPitcherForecast.actualPitcher',
                'awayStartingPitcherForecast.predictedPitcher',
                'awayStartingPitcherForecast.actualPitcher',
            ],
        };

        return $this->availableRelations($gameModel, $relations);
    }
}
