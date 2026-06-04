<?php

namespace App\Services\Api\V2;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class SportTeamQuery
{
    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 100;

    /**
     * @param  array{conference?: string, division?: string, league?: string, search?: string, per_page?: int}  $filters
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
        int|string $team,
        ?Authenticatable $user = null,
    ): Model {
        return $this->query($context, [], $user)->findOrFail($team);
    }

    /**
     * @param  array{conference?: string, division?: string, league?: string, search?: string, per_page?: int}  $filters
     * @return Builder<Model>
     */
    public function query(
        SportContext $context,
        array $filters = [],
        ?Authenticatable $user = null,
    ): Builder {
        $teamModel = $this->teamModel($context);
        $table = (new $teamModel)->getTable();

        return $teamModel::query()
            ->when(($filters['conference'] ?? null) && $this->hasColumn($table, 'conference'), fn (Builder $query): Builder => $query->where('conference', $filters['conference']))
            ->when(($filters['division'] ?? null) && $this->hasColumn($table, 'division'), fn (Builder $query): Builder => $query->where('division', $filters['division']))
            ->when(($filters['league'] ?? null) && $this->hasColumn($table, 'league'), fn (Builder $query): Builder => $query->where('league', $filters['league']))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search): Builder => $this->search($query, $table, $search))
            ->orderBy($this->hasColumn($table, 'abbreviation') ? 'abbreviation' : 'id');
    }

    /**
     * @return class-string<Model>
     */
    private function teamModel(SportContext $context): string
    {
        $teamModel = $context->models['team'] ?? null;

        if (! is_string($teamModel) || ! is_subclass_of($teamModel, Model::class)) {
            abort(404, "Teams are not available for {$context->slug}.");
        }

        return $teamModel;
    }

    private function search(Builder $query, string $table, string $search): Builder
    {
        $columns = array_values(array_filter(
            ['abbreviation', 'location', 'name', 'nickname', 'school', 'mascot', 'display_name', 'short_display_name'],
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

    private function hasColumn(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }

    /**
     * @param  array{per_page?: int}  $filters
     */
    private function perPage(array $filters): int
    {
        return max(1, min((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));
    }
}
