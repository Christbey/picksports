<?php

namespace App\Services\Api\V2;

use App\Services\Api\V2\Concerns\BuildsSportQueries;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SportPlayerQuery
{
    use BuildsSportQueries;

    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 100;

    /**
     * @param  array{team_id?: int, position?: string, status?: string, search?: string, per_page?: int}  $filters
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
     * @param  array{team_id?: int, position?: string, status?: string, search?: string, per_page?: int}  $filters
     */
    public function paginateForTeam(
        SportContext $context,
        int|string $team,
        array $filters = [],
        ?Authenticatable $user = null,
    ): LengthAwarePaginator {
        $filters['team_id'] = (int) $team;

        return $this->paginate($context, $filters, $user);
    }

    public function find(
        SportContext $context,
        int|string $player,
        ?Authenticatable $user = null,
    ): Model {
        return $this->query($context, [], $user)->findOrFail($player);
    }

    /**
     * @param  array{team_id?: int, position?: string, status?: string, search?: string, per_page?: int}  $filters
     * @return Builder<Model>
     */
    public function query(
        SportContext $context,
        array $filters = [],
        ?Authenticatable $user = null,
    ): Builder {
        $playerModel = $this->playerModel($context);
        $table = (new $playerModel)->getTable();

        return $playerModel::query()
            ->with($this->relationsFor($playerModel))
            ->when(($filters['team_id'] ?? null) && $this->hasColumn($table, 'team_id'), fn (Builder $query): Builder => $query->where('team_id', $filters['team_id']))
            ->when(($filters['position'] ?? null) && $this->hasColumn($table, 'position'), fn (Builder $query): Builder => $query->where('position', $filters['position']))
            ->when(($filters['status'] ?? null) && $this->hasColumn($table, 'status'), fn (Builder $query): Builder => $query->where('status', $filters['status']))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search): Builder => $this->search($query, $table, $search))
            ->orderBy($this->orderColumn($table));
    }

    /**
     * @return class-string<Model>
     */
    private function playerModel(SportContext $context): string
    {
        return $this->requireModel($context, 'player', 'Players');
    }

    /**
     * @param  class-string<Model>  $playerModel
     * @return array<int, string>
     */
    private function relationsFor(string $playerModel): array
    {
        return $this->availableRelations($playerModel, ['team']);
    }

    private function search(Builder $query, string $table, string $search): Builder
    {
        $columns = array_values(array_filter(
            ['full_name', 'display_name', 'name', 'first_name', 'last_name', 'jersey_number'],
            fn (string $column): bool => $this->hasColumn($table, $column),
        ));

        if ($columns === []) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($columns, $search): void {
            foreach ($columns as $column) {
                $query->orWhere($column, 'like', "%{$search}%");
            }
        });
    }

    private function orderColumn(string $table): string
    {
        foreach (['full_name', 'display_name', 'name', 'last_name', 'id'] as $column) {
            if ($column === 'id' || $this->hasColumn($table, $column)) {
                return $column;
            }
        }

        return 'id';
    }
}
