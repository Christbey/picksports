<?php

namespace App\Services\Settings;

use App\Http\Resources\Settings\OddsApiPlayerMappingResource;
use App\Models\OddsApiPlayerMapping;
use Illuminate\Pagination\LengthAwarePaginator;

class OddsApiPlayerMappingIndexDataService
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
        $baseQuery = OddsApiPlayerMapping::query()->where('sport', $sport);

        return [
            'total' => (clone $baseQuery)->count(),
            'mapped' => (clone $baseQuery)->whereNotNull('espn_player_name')->count(),
            'unmapped' => (clone $baseQuery)->whereNull('espn_player_name')->count(),
        ];
    }

    public function mappings(string $sport, string $filter, int $perPage = 50): LengthAwarePaginator
    {
        $query = OddsApiPlayerMapping::query()->where('sport', $sport);

        if ($filter === 'mapped') {
            $query->whereNotNull('espn_player_name');
        } elseif ($filter === 'unmapped') {
            $query->whereNull('espn_player_name');
        }

        $mappings = $query
            ->orderByRaw('espn_player_name IS NULL DESC')
            ->orderBy('odds_api_player_name')
            ->paginate($perPage)
            ->appends(['sport' => $sport, 'filter' => $filter]);

        $mappings->through(
            fn (OddsApiPlayerMapping $mapping) => (new OddsApiPlayerMappingResource($mapping))->resolve()
        );

        return $mappings;
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
