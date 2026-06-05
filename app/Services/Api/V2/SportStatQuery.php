<?php

namespace App\Services\Api\V2;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SportStatQuery
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(
        SportContext $context,
        string $type,
        array $filters = [],
        ?Authenticatable $user = null,
    ): LengthAwarePaginator {
        return $this->query($context, $type, $filters, $user)
            ->paginate($this->perPage($filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Model>
     */
    public function query(
        SportContext $context,
        string $type,
        array $filters = [],
        ?Authenticatable $user = null,
    ): Builder {
        $statModel = $this->statModel($context, $type);
        $table = (new $statModel)->getTable();

        return $statModel::query()
            ->with($this->relationsFor($statModel))
            ->when(($filters['game_id'] ?? null) && $this->hasColumn($table, 'game_id'), fn (Builder $query): Builder => $query->where('game_id', $filters['game_id']))
            ->when(($filters['team_id'] ?? null) && $this->hasColumn($table, 'team_id'), fn (Builder $query): Builder => $query->where('team_id', $filters['team_id']))
            ->when(($filters['player_id'] ?? null) && $type === 'player' && $this->hasColumn($table, 'player_id'), fn (Builder $query): Builder => $query->where('player_id', $filters['player_id']))
            ->when($filters['stat_type'] ?? null, fn (Builder $query, string $statType): Builder => $this->whereStatType($query, $table, $statType))
            ->when(($filters['team_type'] ?? null) && $this->hasColumn($table, 'team_type'), fn (Builder $query): Builder => $query->where('team_type', $filters['team_type']))
            ->when($filters['season'] ?? null, fn (Builder $query, int $season): Builder => $this->whereGameColumn($query, 'season', $season))
            ->when($filters['season_type'] ?? null, fn (Builder $query, string $seasonType): Builder => $this->whereGameColumn($query, 'season_type', $seasonType))
            ->when($filters['week'] ?? null, fn (Builder $query, int $week): Builder => $this->whereGameColumn($query, 'week', $week))
            ->when($filters['from_date'] ?? null, fn (Builder $query, string $date): Builder => $this->whereGameDate($query, '>=', $date))
            ->when($filters['to_date'] ?? null, fn (Builder $query, string $date): Builder => $this->whereGameDate($query, '<=', $date))
            ->orderByDesc($this->hasColumn($table, 'updated_at') ? "{$table}.updated_at" : "{$table}.id");
    }

    /**
     * @return Collection<int, int>
     */
    public function availableSeasons(
        SportContext $context,
        string $type,
        ?Authenticatable $user = null,
    ): Collection {
        $statModel = $this->statModel($context, $type);
        $gameModel = $this->gameModel($context);
        $statTable = (new $statModel)->getTable();
        $gameTable = (new $gameModel)->getTable();

        if (! $this->hasColumn($statTable, 'game_id') || ! $this->hasColumn($gameTable, 'season')) {
            return collect();
        }

        return $statModel::query()
            ->join($gameTable, "{$gameTable}.id", '=', "{$statTable}.game_id")
            ->whereNotNull("{$gameTable}.season")
            ->select("{$gameTable}.season")
            ->distinct()
            ->orderByDesc("{$gameTable}.season")
            ->pluck('season')
            ->map(fn (mixed $season): int => (int) $season)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, string>
     */
    public function availableDates(
        SportContext $context,
        string $type,
        array $filters = [],
        ?Authenticatable $user = null,
    ): Collection {
        $statModel = $this->statModel($context, $type);
        $gameModel = $this->gameModel($context);
        $statTable = (new $statModel)->getTable();
        $gameTable = (new $gameModel)->getTable();

        if (! $this->hasColumn($statTable, 'game_id') || ! $this->hasColumn($gameTable, 'game_date')) {
            return collect();
        }

        return $statModel::query()
            ->join($gameTable, "{$gameTable}.id", '=', "{$statTable}.game_id")
            ->when(($filters['season'] ?? null) && $this->hasColumn($gameTable, 'season'), fn (Builder $query): Builder => $query->where("{$gameTable}.season", $filters['season']))
            ->when(($filters['season_type'] ?? null) && $this->hasColumn($gameTable, 'season_type'), fn (Builder $query): Builder => $query->where("{$gameTable}.season_type", $filters['season_type']))
            ->whereNotNull("{$gameTable}.game_date")
            ->selectRaw('DATE('.DB::getQueryGrammar()->wrap("{$gameTable}.game_date").') as game_date')
            ->distinct()
            ->orderBy('game_date')
            ->pluck('game_date')
            ->map(fn (mixed $date): string => (string) $date)
            ->values();
    }

    /**
     * @return class-string<Model>
     */
    private function statModel(SportContext $context, string $type): string
    {
        $key = $type === 'team' ? 'team_stat' : 'player_stat';
        $message = $type === 'team' ? 'Team stats' : 'Player stats';
        $statModel = $context->models[$key] ?? null;

        if (! is_string($statModel) || ! is_subclass_of($statModel, Model::class)) {
            abort(404, "{$message} are not available for {$context->slug}.");
        }

        return $statModel;
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
     * @param  class-string<Model>  $statModel
     * @return array<int, string>
     */
    private function relationsFor(string $statModel): array
    {
        $relations = [];

        if (method_exists($statModel, 'game')) {
            $relations[] = 'game';
            $relations[] = 'game.homeTeam';
            $relations[] = 'game.awayTeam';
        }

        foreach (['team', 'player'] as $relation) {
            if (method_exists($statModel, $relation)) {
                $relations[] = $relation;
            }
        }

        return $relations;
    }

    private function whereStatType(Builder $query, string $table, string $statType): Builder
    {
        foreach (['stat_type', 'team_type'] as $column) {
            if ($this->hasColumn($table, $column)) {
                return $query->where($column, $statType);
            }
        }

        return $query;
    }

    private function whereGameColumn(Builder $query, string $column, mixed $value): Builder
    {
        return $query->whereHas('game', fn (Builder $query): Builder => $query->where($column, $value));
    }

    private function whereGameDate(Builder $query, string $operator, string $date): Builder
    {
        return $query->whereHas('game', fn (Builder $query): Builder => $query->whereDate('game_date', $operator, $date));
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
