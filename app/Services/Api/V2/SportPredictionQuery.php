<?php

namespace App\Services\Api\V2;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SportPredictionQuery
{
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
            ->paginate($this->perPage($filters));
    }

    public function find(
        SportContext $context,
        int|string $prediction,
        ?Authenticatable $user = null,
    ): Model {
        return $this->query($context, [], $user)->findOrFail($prediction);
    }

    public function findForGame(
        SportContext $context,
        int|string $game,
        ?Authenticatable $user = null,
    ): Model {
        return $this->query($context, ['game_id' => (int) $game], $user)->firstOrFail();
    }

    /**
     * @return Collection<int, int>
     */
    public function availableSeasons(SportContext $context, ?Authenticatable $user = null): Collection
    {
        $predictionModel = $this->predictionModel($context);
        $gameModel = $this->gameModel($context);
        $predictionTable = (new $predictionModel)->getTable();
        $gameTable = (new $gameModel)->getTable();

        if ($this->hasColumn($predictionTable, 'season')) {
            return $this->query($context, [], $user)
                ->toBase()
                ->select("{$predictionTable}.season")
                ->whereNotNull("{$predictionTable}.season")
                ->distinct()
                ->orderByDesc("{$predictionTable}.season")
                ->pluck('season')
                ->map(fn (mixed $season): int => (int) $season)
                ->values();
        }

        if (! $this->hasColumn($predictionTable, 'game_id') || ! $this->hasColumn($gameTable, 'season')) {
            return collect();
        }

        return $predictionModel::query()
            ->join($gameTable, "{$gameTable}.id", '=', "{$predictionTable}.game_id")
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
    public function availableDates(SportContext $context, array $filters = [], ?Authenticatable $user = null): Collection
    {
        $predictionModel = $this->predictionModel($context);
        $gameModel = $this->gameModel($context);
        $predictionTable = (new $predictionModel)->getTable();
        $gameTable = (new $gameModel)->getTable();

        if (! $this->hasColumn($predictionTable, 'game_id') || ! $this->hasColumn($gameTable, 'game_date')) {
            return collect();
        }

        $query = $predictionModel::query()
            ->join($gameTable, "{$gameTable}.id", '=', "{$predictionTable}.game_id")
            ->when($filters['season'] ?? null, function (Builder $query, int $season) use ($predictionTable, $gameTable): Builder {
                if ($this->hasColumn($predictionTable, 'season')) {
                    return $query->where("{$predictionTable}.season", $season);
                }

                if ($this->hasColumn($gameTable, 'season')) {
                    return $query->where("{$gameTable}.season", $season);
                }

                return $query;
            })
            ->whereNotNull("{$gameTable}.game_date")
            ->selectRaw('DATE('.DB::getQueryGrammar()->wrap("{$gameTable}.game_date").') as game_date')
            ->distinct()
            ->orderBy('game_date');

        return $query->pluck('game_date')
            ->map(fn (mixed $date): string => (string) $date)
            ->values();
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
        $predictionModel = $this->predictionModel($context);
        $gameModel = $this->gameModel($context);
        $table = (new $predictionModel)->getTable();
        $gameTable = (new $gameModel)->getTable();

        return $predictionModel::query()
            ->with($this->relationsFor($predictionModel, $context))
            ->when($filters['season'] ?? null, function (Builder $query, int $season) use ($table, $gameTable): Builder {
                if ($this->hasColumn($table, 'season')) {
                    return $query->where("{$table}.season", $season);
                }

                return $this->hasColumn($gameTable, 'season')
                    ? $this->whereGameColumn($query, 'season', $season)
                    : $query;
            })
            ->when($filters['season_type'] ?? null, function (Builder $query, string $seasonType) use ($table, $gameTable): Builder {
                if ($this->hasColumn($table, 'season_type')) {
                    return $query->where("{$table}.season_type", $seasonType);
                }

                return $this->hasColumn($gameTable, 'season_type')
                    ? $this->whereGameColumn($query, 'season_type', $seasonType)
                    : $query;
            })
            ->when(($filters['game_id'] ?? null) && $this->hasColumn($table, 'game_id'), fn (Builder $query): Builder => $query->where('game_id', $filters['game_id']))
            ->when($filters['team_id'] ?? null, fn (Builder $query, int $teamId): Builder => $this->whereTeam($query, $teamId))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $this->whereGameColumn($query, 'status', $status))
            ->when($filters['week'] ?? null, fn (Builder $query, int $week): Builder => $this->whereGameColumn($query, 'week', $week))
            ->when($filters['from_date'] ?? null, fn (Builder $query, string $date): Builder => $this->whereGameDate($query, '>=', $date))
            ->when($filters['to_date'] ?? null, fn (Builder $query, string $date): Builder => $this->whereGameDate($query, '<=', $date))
            ->when(array_key_exists('has_value', $filters), fn (Builder $query): Builder => $this->whereHasValue($query, (bool) $filters['has_value']))
            ->orderByDesc($this->hasColumn($table, 'updated_at') ? "{$table}.updated_at" : "{$table}.id");
    }

    /**
     * @return class-string<Model>
     */
    private function predictionModel(SportContext $context): string
    {
        $predictionModel = $context->models['prediction'] ?? null;

        if (! is_string($predictionModel) || ! is_subclass_of($predictionModel, Model::class)) {
            abort(404, "Predictions are not available for {$context->slug}.");
        }

        return $predictionModel;
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
     * @param  class-string<Model>  $predictionModel
     * @return array<int, string>
     */
    private function relationsFor(string $predictionModel, SportContext $context): array
    {
        if (! method_exists($predictionModel, 'game')) {
            return [];
        }

        $gameModel = $context->models['game'] ?? null;
        $gameRelations = [];

        if (is_string($gameModel) && is_subclass_of($gameModel, Model::class)) {
            $gameRelations = array_values(array_filter(
                ['homeTeam', 'awayTeam'],
                fn (string $relation): bool => method_exists($gameModel, $relation),
            ));
        }

        return $gameRelations === []
            ? ['game']
            : array_map(fn (string $relation): string => "game.{$relation}", $gameRelations);
    }

    private function whereTeam(Builder $query, int $teamId): Builder
    {
        return $query->whereHas('game', function (Builder $query) use ($teamId): void {
            $query->where('home_team_id', $teamId)
                ->orWhere('away_team_id', $teamId);
        });
    }

    private function whereGameColumn(Builder $query, string $column, mixed $value): Builder
    {
        return $query->whereHas('game', fn (Builder $query): Builder => $query->where($column, $value));
    }

    private function whereGameDate(Builder $query, string $operator, string $date): Builder
    {
        return $query->whereHas('game', fn (Builder $query): Builder => $query->whereDate('game_date', $operator, $date));
    }

    private function whereHasValue(Builder $query, bool $hasValue): Builder
    {
        return $hasValue
            ? $query->whereNotNull('predicted_spread')
            : $query->whereNull('predicted_spread');
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
