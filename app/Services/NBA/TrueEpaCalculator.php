<?php

namespace App\Services\NBA;

use Illuminate\Support\Collection;

class TrueEpaCalculator
{
    public function __construct(
        protected PlayEpaDataService $playDataService
    ) {}

    /**
     * @param  Collection<int,object>  $plays
     * @return array<int,array{eligible:bool,ep_before:?float,ep_after:?float,epa:?float}>
     */
    public function calculateForGame(Collection $plays, int $homeTeamId, int $awayTeamId): array
    {
        if ($plays->isEmpty()) {
            return [];
        }

        $rows = $plays->values()->all();
        $realizedFuturePoints = $this->buildRealizedFuturePoints($rows, $homeTeamId, $awayTeamId);
        $stateMap = $this->buildExpectedPointsStateMap($rows, $realizedFuturePoints);

        $results = [];
        $count = count($rows);

        for ($i = 0; $i < $count; $i++) {
            $play = $rows[$i];
            $playId = (int) $play->id;

            if (! $this->playDataService->isEpaEligiblePlay($play)) {
                $results[$playId] = [
                    'eligible' => false,
                    'ep_before' => null,
                    'ep_after' => null,
                    'epa' => null,
                ];

                continue;
            }

            $offenseTeamId = (int) $play->possession_team_id;
            $stateKey = $this->stateKey($play);
            $epBefore = $stateMap[$stateKey] ?? 0.0;

            $nextEligible = $this->findNextEligiblePlay($rows, $i + 1);
            if ($nextEligible === null) {
                $epAfter = 0.0;
            } else {
                $nextKey = $this->stateKey($nextEligible);
                $nextEp = $stateMap[$nextKey] ?? 0.0;
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
            if (! $this->playDataService->isEpaEligiblePlay($play)) {
                continue;
            }

            $key = $this->stateKey($play);
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
            if ($this->playDataService->isEpaEligiblePlay($play)) {
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

    private function stateKey(object $play): string
    {
        $period = is_numeric($play->period ?? null) ? (int) $play->period : 0;
        $clock = (string) ($play->clock ?? '');
        $home = (int) ($play->home_score ?? 0);
        $away = (int) ($play->away_score ?? 0);

        return implode('|', [
            $this->periodBucket($period),
            $this->clockBucket($clock),
            $this->scoreMarginBucket($home - $away),
        ]);
    }

    private function periodBucket(int $period): string
    {
        if ($period <= 1) {
            return 'Q1';
        }
        if ($period === 2) {
            return 'Q2';
        }
        if ($period === 3) {
            return 'Q3';
        }
        if ($period === 4) {
            return 'Q4';
        }

        return 'OT';
    }

    private function clockBucket(string $clock): string
    {
        $parts = explode(':', trim($clock));
        $minutes = isset($parts[0]) && is_numeric($parts[0]) ? (int) $parts[0] : 0;
        $seconds = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 0;
        $remaining = max(0, min(12 * 60, ($minutes * 60) + $seconds));

        if ($remaining >= 9 * 60) {
            return '12-9';
        }
        if ($remaining >= 6 * 60) {
            return '9-6';
        }
        if ($remaining >= 3 * 60) {
            return '6-3';
        }

        return '3-0';
    }

    private function scoreMarginBucket(int $margin): string
    {
        if ($margin <= -16) {
            return '-16+';
        }
        if ($margin <= -11) {
            return '-11--15';
        }
        if ($margin <= -6) {
            return '-6--10';
        }
        if ($margin <= -1) {
            return '-1--5';
        }
        if ($margin <= 5) {
            return '0-5';
        }
        if ($margin <= 10) {
            return '6-10';
        }
        if ($margin <= 15) {
            return '11-15';
        }

        return '16+';
    }
}
