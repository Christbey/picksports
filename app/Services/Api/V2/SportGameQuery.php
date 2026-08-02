<?php

namespace App\Services\Api\V2;

use App\Services\Sports\SportsDateWindowService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class SportGameQuery
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 500;

    /**
     * @param  array{status?: string, season?: int, from_date?: string, to_date?: string, per_page?: int, team_id?: int}  $filters
     */
    public function paginate(
        SportContext $context,
        array $filters = [],
        ?Authenticatable $user = null,
    ): LengthAwarePaginator {
        return $this->query($context, $filters, $user)
            ->paginate($this->perPage($filters));
    }

    public function find(
        SportContext $context,
        int|string $game,
        ?Authenticatable $user = null,
    ): Model {
        return $this->query($context, [], $user)->findOrFail($game);
    }

    /**
     * @param  array{status?: string, season?: int, from_date?: string, to_date?: string, per_page?: int, team_id?: int}  $filters
     * @return Builder<Model>
     */
    public function query(
        SportContext $context,
        array $filters = [],
        ?Authenticatable $user = null,
    ): Builder {
        $gameModel = $this->gameModel($context);

        return $gameModel::query()
            ->with($this->relationsFor($gameModel))
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
        $gameModel = $context->models['game'] ?? null;

        if (! is_string($gameModel) || ! is_subclass_of($gameModel, Model::class)) {
            abort(404, "Games are not available for {$context->slug}.");
        }

        return $gameModel;
    }

    /**
     * @param  class-string<Model>  $gameModel
     * @return array<int, string>
     */
    private function relationsFor(string $gameModel): array
    {
        return array_values(array_filter(
            [
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
            fn (string $relation): bool => method_exists($gameModel, explode('.', $relation, 2)[0]),
        ));
    }

    /**
     * @param  array{per_page?: int}  $filters
     */
    private function perPage(array $filters): int
    {
        return max(1, min((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));
    }
}
