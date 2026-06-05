<?php

namespace App\Services\Api\V2;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SportInjuryQuery
{
    /**
     * @param  array{active?: bool, team_id?: int, status?: string}  $filters
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

        return DB::table("{$injuryTable} as i")
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
            ])
            ->when(
                $filters['active'] ?? true,
                fn ($query) => $query->where('i.is_active', true)
            )
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
            ->orderByDesc('i.updated_at')
            ->get();
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
