<?php

namespace App\Services\Api\V2;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SportGameQuery
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * @param  array{status?: string, season?: int, from_date?: string, to_date?: string, per_page?: int}  $filters
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
     * @param  array{status?: string, season?: int, from_date?: string, to_date?: string, per_page?: int}  $filters
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
            ->when($filters['from_date'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('game_date', '>=', $date))
            ->when($filters['to_date'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('game_date', '<=', $date))
            ->orderBy('game_date');
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
            ['homeTeam', 'awayTeam', 'prediction'],
            fn (string $relation): bool => method_exists($gameModel, $relation),
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
