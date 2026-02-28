<?php

namespace App\Services\Settings;

use App\Http\Resources\Settings\EspnTeamOptionResource;
use App\Http\Resources\Settings\OddsApiTeamMappingResource;
use App\Models\OddsApiTeamMapping;
use App\Support\ResourcePayload;
use Illuminate\Pagination\LengthAwarePaginator;

class OddsApiTeamMappingIndexDataService
{
    public function normalizeSport(string $sport, array $sportConfigs, string $defaultSport): string
    {
        return isset($sportConfigs[$sport]) ? $sport : $defaultSport;
    }

    /**
     * @return array<string, int>
     */
    public function stats(string $sport): array
    {
        $baseQuery = OddsApiTeamMapping::query()->where('sport', $sport);

        return [
            'total' => (clone $baseQuery)->count(),
            'mapped' => (clone $baseQuery)->whereNotNull('espn_team_name')->count(),
            'unmapped' => (clone $baseQuery)->whereNull('espn_team_name')->count(),
        ];
    }

    public function mappings(string $sport, string $filter, int $perPage = 50): LengthAwarePaginator
    {
        $query = OddsApiTeamMapping::query()->where('sport', $sport);

        if ($filter === 'mapped') {
            $query->whereNotNull('espn_team_name');
        } elseif ($filter === 'unmapped') {
            $query->whereNull('espn_team_name');
        }

        $mappings = $query
            ->orderByRaw('espn_team_name IS NULL DESC')
            ->orderBy('odds_api_team_name')
            ->paginate($perPage)
            ->appends(['sport' => $sport, 'filter' => $filter]);

        $mappings->through(
            fn (OddsApiTeamMapping $mapping) => (new OddsApiTeamMappingResource($mapping))->resolve()
        );

        return $mappings;
    }

    /**
     * @param  array{teamModel: class-string<\Illuminate\Database\Eloquent\Model>, teamField: string}  $config
     * @return array<int, array<string, mixed>>
     */
    public function espnTeams(array $config): array
    {
        $teamModel = $config['teamModel'];
        $teamField = $config['teamField'];
        $teams = $teamModel::query()
            ->select('id', $teamField.' as name', 'abbreviation')
            ->orderBy($teamField)
            ->get();

        return ResourcePayload::from(EspnTeamOptionResource::collection($teams));
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public function sports(array $sportConfigs): array
    {
        return collect($sportConfigs)->map(fn (array $config, string $key) => [
            'key' => $key,
            'label' => $config['label'],
        ])->values()->all();
    }
}
