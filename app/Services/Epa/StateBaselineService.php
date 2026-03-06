<?php

namespace App\Services\Epa;

use App\Models\EpaStateBaseline;
use Illuminate\Support\Facades\DB;

class StateBaselineService
{
    /**
     * @var array<string,array<string,float>>
     */
    private array $mapCache = [];

    /**
     * @return array<string,float>
     */
    public function getMap(string $sport, int $season): array
    {
        $cacheKey = "{$sport}:{$season}";
        if (isset($this->mapCache[$cacheKey])) {
            return $this->mapCache[$cacheKey];
        }

        $map = EpaStateBaseline::query()
            ->where('sport', $sport)
            ->where('season', $season)
            ->pluck('expected_points', 'state_key')
            ->map(fn ($value) => (float) $value)
            ->all();

        $this->mapCache[$cacheKey] = $map;

        return $map;
    }

    /**
     * @param  array<int,array{state_key:string,expected_points:float,sample_size:int}>  $rows
     */
    public function replaceSeasonBaseline(string $sport, int $season, ?int $sourceSeason, array $rows): void
    {
        DB::transaction(function () use ($sport, $season, $sourceSeason, $rows) {
            EpaStateBaseline::query()
                ->where('sport', $sport)
                ->where('season', $season)
                ->delete();

            if ($rows === []) {
                return;
            }

            $now = now();
            $payload = array_map(function (array $row) use ($sport, $season, $sourceSeason, $now): array {
                return [
                    'sport' => $sport,
                    'season' => $season,
                    'source_season' => $sourceSeason,
                    'state_key' => $row['state_key'],
                    'expected_points' => round((float) $row['expected_points'], 4),
                    'sample_size' => (int) $row['sample_size'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $rows);

            foreach (array_chunk($payload, 1000) as $chunk) {
                EpaStateBaseline::query()->insert($chunk);
            }
        });

        unset($this->mapCache["{$sport}:{$season}"]);
    }
}
