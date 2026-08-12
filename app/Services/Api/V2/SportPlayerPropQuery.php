<?php

namespace App\Services\Api\V2;

use App\Services\Api\V2\Concerns\BuildsSportQueries;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SportPlayerPropQuery
{
    use BuildsSportQueries;

    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(
        SportContext $context,
        array $filters = [],
        ?Authenticatable $user = null,
    ): LengthAwarePaginator {
        return $this->query($context, $filters, $user)
            ->paginate($this->perPage($filters, self::DEFAULT_PER_PAGE, self::MAX_PER_PAGE));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Model>
     */
    public function query(
        SportContext $context,
        array $filters = [],
        ?Authenticatable $user = null,
    ): Builder {
        $propModel = $this->propModel($context);
        $table = (new $propModel)->getTable();

        return $propModel::query()
            ->with($this->relationsFor($propModel, $context))
            ->when(($filters['game_id'] ?? null) && $this->hasColumn($table, 'game_id'), fn (Builder $query): Builder => $query->where('game_id', $filters['game_id']))
            ->when(($filters['player_id'] ?? null) && $this->hasColumn($table, 'player_id'), fn (Builder $query): Builder => $query->where('player_id', $filters['player_id']))
            ->when(($filters['market'] ?? null) && $this->hasColumn($table, 'market'), fn (Builder $query): Builder => $query->where('market', $filters['market']))
            ->when(($filters['bookmaker'] ?? null) && $this->hasColumn($table, 'bookmaker'), fn (Builder $query): Builder => $query->where('bookmaker', $filters['bookmaker']))
            ->when(($filters['recommended_side'] ?? null) && $this->hasColumn($table, 'recommended_side'), fn (Builder $query): Builder => $query->where('recommended_side', $filters['recommended_side']))
            ->when(($filters['only_ungraded'] ?? null) && $this->hasColumn($table, 'graded_at'), fn (Builder $query): Builder => $query->whereNull('graded_at'))
            ->when($filters['date'] ?? null, fn (Builder $query, string $date): Builder => $this->whereGameDate($query, '=', $date))
            ->when($filters['from_date'] ?? null, fn (Builder $query, string $date): Builder => $this->whereGameDate($query, '>=', $date))
            ->when($filters['to_date'] ?? null, fn (Builder $query, string $date): Builder => $this->whereGameDate($query, '<=', $date))
            ->orderByDesc($this->hasColumn($table, 'fetched_at') ? "{$table}.fetched_at" : "{$table}.id");
    }

    /**
     * @return class-string<Model>
     */
    private function propModel(SportContext $context): string
    {
        return $this->requireModel($context, 'player_prop', 'Player props');
    }

    /**
     * @param  class-string<Model>  $propModel
     * @return array<int, string>
     */
    private function relationsFor(string $propModel, SportContext $context): array
    {
        $relations = array_values(array_filter(
            ['player', 'game'],
            fn (string $relation): bool => method_exists($propModel, $relation),
        ));

        $gameModel = $context->models['game'] ?? null;
        if (in_array('game', $relations, true) && is_string($gameModel) && is_subclass_of($gameModel, Model::class)) {
            foreach (['homeTeam', 'awayTeam'] as $gameRelation) {
                if (method_exists($gameModel, $gameRelation)) {
                    $relations[] = "game.{$gameRelation}";
                }
            }
        }

        return $relations;
    }

    private function whereGameDate(Builder $query, string $operator, string $date): Builder
    {
        return $query->whereHas('game', fn (Builder $query): Builder => $query->whereDate('game_date', $operator, $date));
    }
}
