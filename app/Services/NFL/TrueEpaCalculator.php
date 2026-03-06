<?php

namespace App\Services\NFL;

use App\Services\Epa\StateBaselineService;
use Illuminate\Support\Collection;

class TrueEpaCalculator
{
    public function __construct(
        protected PlayEpaDataService $playDataService,
        protected StateBaselineService $stateBaselineService
    ) {}

    /**
     * @param  Collection<int,object>  $plays
     * @return array<int,array{eligible:bool,ep_before:?float,ep_after:?float,epa:?float}>
     */
    public function calculateForGame(
        Collection $plays,
        int $homeTeamId,
        int $awayTeamId,
        ?int $season = null
    ): array
    {
        if ($plays->isEmpty()) {
            return [];
        }

        $rows = $plays->values()->all();
        $realizedFuturePoints = $this->buildRealizedFuturePoints($rows, $homeTeamId, $awayTeamId);
        $stateMap = $this->buildExpectedPointsStateMap($rows, $realizedFuturePoints);
        $baselineMap = $this->resolveBaselineMap($season);

        $results = [];
        $count = count($rows);

        for ($i = 0; $i < $count; $i++) {
            $play = $rows[$i];
            $playId = (int) $play->id;

            if (! $this->playDataService->isEpaEligiblePlay($play) || ! is_numeric($play->possession_team_id ?? null)) {
                $results[$playId] = [
                    'eligible' => false,
                    'ep_before' => null,
                    'ep_after' => null,
                    'epa' => null,
                ];
                continue;
            }

            $offenseTeamId = (int) $play->possession_team_id;
            $stateKey = $this->stateKeyForPlay($play);
            $epBefore = $baselineMap[$stateKey] ?? $stateMap[$stateKey] ?? 0.0;

            $nextEligible = $this->findNextEligiblePlay($rows, $i + 1);
            if ($nextEligible === null) {
                $epAfter = 0.0;
            } else {
                $nextStateKey = $this->stateKeyForPlay($nextEligible);
                $nextEp = $baselineMap[$nextStateKey] ?? $stateMap[$nextStateKey] ?? 0.0;
                $nextOffenseTeamId = (int) $nextEligible->possession_team_id;
                $epAfter = $nextOffenseTeamId === $offenseTeamId ? $nextEp : -$nextEp;
            }

            $scoreDelta = $this->scoreDeltaForOffense($rows, $i, $offenseTeamId, $homeTeamId, $awayTeamId);
            $epa = $scoreDelta + ($epAfter - $epBefore);

            $results[$playId] = [
                'eligible' => true,
                'ep_before' => round($epBefore, 3),
                'ep_after' => round($epAfter, 3),
                'epa' => round($epa, 3),
            ];
        }

        return $results;
    }

    /**
     * @param  array<int,object>  $rows
     * @return array<int,float>
     */
    private function buildRealizedFuturePoints(array $rows, int $homeTeamId, int $awayTeamId): array
    {
        $results = [];
        $count = count($rows);

        for ($i = 0; $i < $count; $i++) {
            $play = $rows[$i];
            $playId = (int) $play->id;

            if (! is_numeric($play->possession_team_id ?? null)) {
                $results[$playId] = 0.0;
                continue;
            }

            $offenseTeamId = (int) $play->possession_team_id;
            $nextScore = 0.0;

            for ($j = $i + 1; $j < $count; $j++) {
                $delta = $this->scoreDeltaBetween($rows[$j - 1], $rows[$j], $offenseTeamId, $homeTeamId, $awayTeamId);
                if (abs($delta) > 0.0001) {
                    $nextScore = $delta;
                    break;
                }
            }

            $results[$playId] = $nextScore;
        }

        return $results;
    }

    /**
     * @param  array<int,object>  $rows
     * @param  array<int,float>  $realizedFuturePoints
     * @return array<string,float>
     */
    private function buildExpectedPointsStateMap(array $rows, array $realizedFuturePoints): array
    {
        $stateBuckets = [];

        foreach ($rows as $play) {
            if (! $this->playDataService->isEpaEligiblePlay($play) || ! is_numeric($play->possession_team_id ?? null)) {
                continue;
            }

            $key = $this->stateKeyForPlay($play);
            $value = (float) ($realizedFuturePoints[(int) $play->id] ?? 0.0);
            if (! isset($stateBuckets[$key])) {
                $stateBuckets[$key] = ['sum' => 0.0, 'count' => 0];
            }

            $stateBuckets[$key]['sum'] += $value;
            $stateBuckets[$key]['count']++;
        }

        $stateMap = [];
        foreach ($stateBuckets as $key => $bucket) {
            $count = max(1, (int) $bucket['count']);
            $stateMap[$key] = $bucket['sum'] / $count;
        }

        return $stateMap;
    }

    /**
     * @param  array<int,object>  $rows
     */
    private function findNextEligiblePlay(array $rows, int $startIndex): ?object
    {
        $count = count($rows);
        for ($i = $startIndex; $i < $count; $i++) {
            $play = $rows[$i];
            if ($this->playDataService->isEpaEligiblePlay($play) && is_numeric($play->possession_team_id ?? null)) {
                return $play;
            }
        }

        return null;
    }

    /**
     * @param  array<int,object>  $rows
     */
    private function scoreDeltaForOffense(array $rows, int $index, int $offenseTeamId, int $homeTeamId, int $awayTeamId): float
    {
        if ($index <= 0) {
            return 0.0;
        }

        return $this->scoreDeltaBetween($rows[$index - 1], $rows[$index], $offenseTeamId, $homeTeamId, $awayTeamId);
    }

    private function scoreDeltaBetween(object $before, object $after, int $offenseTeamId, int $homeTeamId, int $awayTeamId): float
    {
        $homeBefore = (int) ($before->home_score ?? 0);
        $awayBefore = (int) ($before->away_score ?? 0);
        $homeAfter = (int) ($after->home_score ?? 0);
        $awayAfter = (int) ($after->away_score ?? 0);

        $homeDelta = $homeAfter - $homeBefore;
        $awayDelta = $awayAfter - $awayBefore;

        if ($offenseTeamId === $homeTeamId) {
            return (float) ($homeDelta - $awayDelta);
        }

        if ($offenseTeamId === $awayTeamId) {
            return (float) ($awayDelta - $homeDelta);
        }

        return 0.0;
    }

    public function stateKeyForPlay(object $play): string
    {
        $down = (int) ($play->down ?? 0);
        $distance = (int) ($play->distance ?? 0);
        $yte = (int) ($play->yards_to_endzone ?? 0);

        return implode('|', [
            max(1, min(4, $down)),
            $this->distanceBucket($distance),
            $this->yardsToEndzoneBucket($yte),
        ]);
    }

    private function distanceBucket(int $distance): string
    {
        if ($distance <= 1) {
            return '1';
        }
        if ($distance <= 3) {
            return '2-3';
        }
        if ($distance <= 6) {
            return '4-6';
        }
        if ($distance <= 10) {
            return '7-10';
        }
        if ($distance <= 15) {
            return '11-15';
        }

        return '16+';
    }

    private function yardsToEndzoneBucket(int $yte): string
    {
        $clamped = max(1, min(99, $yte));
        $start = (int) (floor(($clamped - 1) / 10) * 10) + 1;
        $end = min(100, $start + 9);

        return "{$start}-{$end}";
    }

    /**
     * @return array<string,float>
     */
    private function resolveBaselineMap(?int $season): array
    {
        if ($season === null || $season <= 0) {
            return [];
        }

        try {
            $enabled = (bool) config('epa.state_baseline.enabled', false);
        } catch (\Throwable) {
            return [];
        }

        if (! $enabled) {
            return [];
        }

        return $this->stateBaselineService->getMap('nfl', $season);
    }
}
