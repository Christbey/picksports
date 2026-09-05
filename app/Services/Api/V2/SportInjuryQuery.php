<?php

namespace App\Services\Api\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SportInjuryQuery
{
    /**
     * @param  array{active?: bool, actionable?: bool, team_id?: int, status?: string, limit?: int}  $filters
     * @return Collection<int, object>
     */
    public function get(SportContext $context, array $filters = []): Collection
    {
        $injuryTable = "{$context->slug}_player_injuries";
        $playerTable = "{$context->slug}_players";
        $teamTable = "{$context->slug}_teams";

        if (! Schema::hasTable($injuryTable) || ! Schema::hasTable($teamTable) || ! Schema::hasTable($playerTable)) {
            return collect();
        }

        $nameColumn = $this->resolvePlayerNameColumn(Schema::getColumnListing($playerTable));

        $playerColumns = Schema::getColumnListing($playerTable);
        $positionColumn = in_array('position', $playerColumns, true)
            ? 'p.position as player_position'
            : DB::raw('NULL as player_position');
        $query = DB::table("{$injuryTable} as i")
            ->join("{$teamTable} as t", 't.id', '=', 'i.team_id')
            ->leftJoin("{$playerTable} as p", 'p.id', '=', 'i.player_id')
            ->select([
                'i.id',
                'i.player_id',
                'i.team_id',
                'i.status',
                'i.detail',
                'i.type',
                'i.injury_date',
                'i.return_date',
                'i.source_updated_at',
                'i.is_active',
                'i.updated_at',
                't.abbreviation as team_abbreviation',
                DB::raw($nameColumn.' as player_name'),
                $positionColumn,
            ])
            ->when(
                $filters['team_id'] ?? null,
                fn ($query, int $teamId) => $query->where('i.team_id', $teamId)
            )
            ->when(
                $filters['status'] ?? null,
                fn ($query, string $status) => $query->where('i.status', $status)
            )
            ->orderBy('t.abbreviation')
            ->orderByDesc('i.is_active')
            ->orderByDesc('i.updated_at');

        if (array_key_exists('active', $filters)) {
            $query->where('i.is_active', $filters['active']);
        } else {
            $query->where('i.is_active', true);
        }

        if ($filters['actionable'] ?? false) {
            $query
                ->whereNotNull('i.status')
                ->whereRaw("LOWER(TRIM(i.status)) NOT IN ('active', 'available', 'healthy')")
                ->whereRaw("LOWER(TRIM(COALESCE(i.type, ''))) NOT LIKE '%injury_status_active%'")
                ->where(function ($builder): void {
                    $builder->whereNull('i.return_date')
                        ->orWhereDate('i.return_date', '>=', now()->toDateString());
                });
        }

        $rows = $query
            ->limit((int) ($filters['limit'] ?? 500))
            ->get();

        return $context->slug === 'nfl'
            ? $this->enrichNflRows($rows)
            : $rows;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{total: int, teams: int}
     */
    public function summary(Collection $rows): array
    {
        return [
            'total' => $rows->count(),
            'teams' => $rows->pluck('team_id')->unique()->count(),
        ];
    }

    /**
     * @return array{last_observed_at:?string,latest_source_updated_at:?string,age_hours:?int,is_stale:bool,stale_rows:int,max_age_hours:int}
     */
    public function freshness(SportContext $context, Collection $rows, int $maxAgeHours = 48): array
    {
        $injuryTable = "{$context->slug}_player_injuries";
        $latestSource = Schema::hasTable($injuryTable)
            ? DB::table($injuryTable)->max('source_updated_at')
            : null;
        $lastObserved = Schema::hasTable($injuryTable)
            ? DB::table($injuryTable)->max('updated_at')
            : null;
        $latestSourceAt = $latestSource ? Carbon::parse($latestSource) : null;
        $lastObservedAt = $lastObserved ? Carbon::parse($lastObserved) : null;
        $staleRows = $rows->filter(function (object $row) use ($maxAgeHours): bool {
            if (! $row->source_updated_at) {
                return true;
            }

            return Carbon::parse($row->source_updated_at)->lt(now()->subHours($maxAgeHours));
        })->count();

        return [
            'last_observed_at' => $lastObservedAt?->toIso8601String(),
            'latest_source_updated_at' => $latestSourceAt?->toIso8601String(),
            'age_hours' => $lastObservedAt ? max(0, (int) $lastObservedAt->diffInHours(now())) : null,
            'is_stale' => $lastObservedAt === null || $lastObservedAt->lt(now()->subHours($maxAgeHours)),
            'stale_rows' => $staleRows,
            'max_age_hours' => $maxAgeHours,
        ];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    private function enrichNflRows(Collection $rows): Collection
    {
        $playerIds = $rows->pluck('player_id')->filter()->map(fn (mixed $id): int => (int) $id)->all();
        $season = now()->month <= 2 ? now()->year - 1 : now()->year;
        $depthEntries = DB::table('nfl_depth_chart_entries')
            ->whereIn('player_id', $playerIds)
            ->where('season', '<=', $season)
            ->orderByDesc('season')
            ->orderByDesc('is_starter')
            ->orderBy('depth_rank')
            ->get()
            ->unique('player_id')
            ->keyBy('player_id');

        return $rows->map(function (object $row) use ($depthEntries): object {
            $entry = $depthEntries->get($row->player_id);
            $position = strtoupper(trim((string) ($entry->position_code ?? $row->player_position ?? '')));
            $depthRank = is_numeric($entry->depth_rank ?? null) ? (int) $entry->depth_rank : null;
            $isStarter = (bool) ($entry->is_starter ?? false);
            $availability = $this->nflAvailabilityProbability((string) ($row->status ?? ''));
            $impactWeight = $this->nflImpactWeight($position, $depthRank, $isStarter);
            $expectedImpact = round($impactWeight * (1.0 - $availability), 2);
            $sourceUpdatedAt = $row->source_updated_at ? Carbon::parse($row->source_updated_at) : null;

            $row->position = $position !== '' ? $position : null;
            $row->depth_rank = $depthRank;
            $row->is_starter = $isStarter;
            $row->availability_probability = round($availability, 2);
            $row->impact_weight = round($impactWeight, 2);
            $row->expected_impact = $expectedImpact;
            $row->impact_level = match (true) {
                $expectedImpact >= 1.75 => 'critical',
                $expectedImpact >= 1.20 => 'high',
                $expectedImpact >= 0.50 => 'medium',
                default => 'low',
            };
            $row->source = 'espn';
            $row->is_stale = $sourceUpdatedAt === null || $sourceUpdatedAt->lt(now()->subHours(48));
            unset($row->player_position);

            return $row;
        });
    }

    private function nflAvailabilityProbability(string $status): float
    {
        $status = strtolower(trim($status));

        return match (true) {
            str_contains($status, 'out'),
            str_contains($status, 'inactive'),
            str_contains($status, 'injured reserve'),
            str_contains($status, 'suspension') => 0.0,
            str_contains($status, 'doubtful') => (float) config('nfl.predictions.player_position_grades.doubtful_availability', 0.25),
            str_contains($status, 'questionable') => (float) config('nfl.predictions.player_position_grades.questionable_availability', 0.60),
            str_contains($status, 'probable') => (float) config('nfl.predictions.player_position_grades.probable_availability', 0.90),
            default => 0.5,
        };
    }

    private function nflImpactWeight(string $position, ?int $depthRank, bool $isStarter): float
    {
        $isPrimary = $isStarter || $depthRank === 1;
        $weight = $isStarter
            ? (float) config('nfl.predictions.depth_chart.starter_multiplier', 1.35)
            : ($depthRank !== null && $depthRank <= 2
                ? (float) config('nfl.predictions.depth_chart.rotation_multiplier', 1.10)
                : 1.0);

        if ($isPrimary && $position === 'QB') {
            return max($weight, (float) config('nfl.predictions.depth_chart.qb_multiplier', 2.40));
        }

        if (($isPrimary || ($depthRank !== null && $depthRank <= 2)) && in_array($position, ['WR', 'RB', 'TE'], true)) {
            return max($weight, (float) config('nfl.predictions.depth_chart.skill_multiplier', 1.45));
        }

        $roleMultipliers = (array) config('nfl.predictions.depth_chart.role_multipliers', []);

        return max(
            $weight,
            $isPrimary && is_numeric($roleMultipliers[$position] ?? null)
                ? (float) $roleMultipliers[$position]
                : 1.0,
        );
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function resolvePlayerNameColumn(array $columns): string
    {
        foreach (['full_name', 'display_name', 'name', 'short_name'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return "NULLIF(p.{$candidate}, '')";
            }
        }

        if (in_array('first_name', $columns, true) || in_array('last_name', $columns, true)) {
            return "NULLIF(TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))), '')";
        }

        return "'Unknown Player'";
    }
}
