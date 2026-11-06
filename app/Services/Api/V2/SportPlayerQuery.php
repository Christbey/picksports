<?php

namespace App\Services\Api\V2;

use App\Models\CBB\RosterSnapshot;
use App\Services\Api\V2\Concerns\BuildsSportQueries;
use App\Support\BasketballSeason;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class SportPlayerQuery
{
    use BuildsSportQueries;

    private array $rosterObservations = [];

    private ?bool $rosterStorageReady = null;

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
        if ($context->slug === 'cbb' && isset($filters['team_id'])) {
            return $this->cbbRosterPaginator($context, $filters, $user);
        }

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

    private function rosterSnapshot(int $teamId, int $season): ?RosterSnapshot
    {
        $this->rosterStorageReady ??= Schema::hasTable('cbb_roster_snapshots') && Schema::hasTable('cbb_roster_entries');
        if (! $this->rosterStorageReady) {
            return null;
        }
        $key = $teamId.':'.$season;
        if (! array_key_exists($key, $this->rosterObservations)) {
            $this->rosterObservations[$key] = RosterSnapshot::knownAt($teamId, $season, now());
        }

        return $this->rosterObservations[$key];
    }

    public function rosterEvidence(SportContext $context, array $filters): array
    {
        if ($context->slug !== 'cbb' || ! isset($filters['team_id'])) {
            return [];
        }
        $season = (int) ($filters['season'] ?? BasketballSeason::forDate());
        $snapshot = $this->rosterSnapshot((int) $filters['team_id'], $season);
        $stale = $snapshot && $snapshot->observed_at->lt(now()->subHours(72));

        return ['season' => $season, 'snapshot_id' => $snapshot?->id, 'observed_at' => $snapshot?->observed_at->toISOString(),
            'status' => $snapshot ? ($stale ? 'stale' : 'available') : ($this->rosterStorageReady ? 'unavailable' : 'not_initialized'),
            'warnings' => $snapshot ? ($stale ? ['season_roster_stale'] : []) : [$this->rosterStorageReady ? 'season_roster_unavailable' : 'season_roster_storage_unavailable']];
    }

    private function cbbRosterPaginator(SportContext $context, array $filters, ?Authenticatable $user): LengthAwarePaginator
    {
        $season = (int) ($filters['season'] ?? BasketballSeason::forDate());
        $snapshot = $this->rosterSnapshot((int) $filters['team_id'], $season)?->load(['entries', 'team']);
        $entries = $snapshot?->entries->keyBy('player_id') ?? collect();
        $ids = $entries->when($filters['position'] ?? null, fn ($rows, $position) => $rows->filter(fn ($entry): bool => data_get($entry->getAttribute('attributes'), 'position') === $position))->keys();
        unset($filters['team_id'], $filters['position']);
        $paginator = $this->query($context, $filters, $user)->whereIn('id', $ids)->paginate($this->perPage($filters, self::DEFAULT_PER_PAGE, self::MAX_PER_PAGE));
        $paginator->getCollection()->each(function (Model $player) use ($snapshot, $entries): void {
            foreach ($entries[$player->id]->getAttribute('attributes') as $key => $value) {
                $player->setAttribute($key, $value);
            }
            $player->setAttribute('team_id', $snapshot->team_id);
            $player->setRelation('team', $snapshot->team);
        });

        return $paginator;
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
