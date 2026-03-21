<?php

namespace App\Services\NBA;

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
        ?int $season = null,
        string $sport = 'nba'
    ): array {
        if ($plays->isEmpty()) {
            return [];
        }

        $rows = $plays->values()->all();
        [$halfMode, $periodDurationSeconds] = $this->derivePeriodContext($rows);
        $realizedFuturePoints = $this->buildRealizedFuturePoints($rows, $homeTeamId, $awayTeamId);
        $stateMap = $this->buildExpectedPointsStateMap($rows, $realizedFuturePoints, $halfMode, $periodDurationSeconds);
        $baselineMap = $this->resolveBaselineMap($sport, $season);

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
            $stateKey = $this->stateKeyForPlay($play, $halfMode, $periodDurationSeconds);
            $epBefore = $baselineMap[$stateKey] ?? $stateMap[$stateKey] ?? 0.0;

            $nextEligible = $this->findNextEligiblePlay($rows, $i + 1);
            if ($nextEligible === null) {
                $epAfter = 0.0;
            } else {
                $nextKey = $this->stateKeyForPlay($nextEligible, $halfMode, $periodDurationSeconds);
                $nextEp = $baselineMap[$nextKey] ?? $stateMap[$nextKey] ?? 0.0;
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
    private function buildExpectedPointsStateMap(
        array $rows,
        array $realizedFuturePoints,
        bool $halfMode,
        int $periodDurationSeconds
    ): array {
        $stateBuckets = [];

        foreach ($rows as $play) {
            if (! $this->playDataService->isEpaEligiblePlay($play)) {
                continue;
            }

            $key = $this->stateKeyForPlay($play, $halfMode, $periodDurationSeconds);
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

    public function stateKeyForPlay(object $play, bool $halfMode, int $periodDurationSeconds): string
    {
        $period = is_numeric($play->period ?? null) ? (int) $play->period : 0;
        $clock = (string) ($play->clock ?? '');
        $home = (int) ($play->home_score ?? 0);
        $away = (int) ($play->away_score ?? 0);

        return implode('|', [
            $this->periodBucket($period, $halfMode),
            $this->clockBucket($clock, $periodDurationSeconds),
            $this->scoreMarginBucket($home - $away),
        ]);
    }

    private function periodBucket(int $period, bool $halfMode): string
    {
        if ($halfMode) {
            if ($period <= 1) {
                return 'H1';
            }
            if ($period === 2) {
                return 'H2';
            }

            return 'OT';
        }

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

    private function clockBucket(string $clock, int $periodDurationSeconds): string
    {
        $parts = explode(':', trim($clock));
        $minutes = isset($parts[0]) && is_numeric($parts[0]) ? (int) $parts[0] : 0;
        $seconds = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 0;
        $remaining = max(0, min($periodDurationSeconds, ($minutes * 60) + $seconds));
        $step = max(1, (int) floor($periodDurationSeconds / 4));

        if ($remaining >= ($step * 3)) {
            return 'q4';
        }
        if ($remaining >= ($step * 2)) {
            return 'q3';
        }
        if ($remaining >= $step) {
            return 'q2';
        }

        return 'q1';
    }

    /**
     * @param  array<int,object>|Collection<int,object>  $rows
     * @return array{0:bool,1:int}
     */
    public function derivePeriodContext(array|Collection $rows): array
    {
        $rows = $rows instanceof Collection ? $rows->values()->all() : $rows;

        $maxMinutes = 0;
        $maxPeriod = 0;

        foreach ($rows as $play) {
            $period = is_numeric($play->period ?? null) ? (int) $play->period : 0;
            $maxPeriod = max($maxPeriod, $period);

            $clock = trim((string) ($play->clock ?? ''));
            if ($clock === '' || ! str_contains($clock, ':')) {
                continue;
            }

            [$mins] = array_pad(explode(':', $clock, 2), 2, '0');
            if (is_numeric($mins)) {
                $maxMinutes = max($maxMinutes, (int) $mins);
            }
        }

        $halfMode = $maxPeriod <= 2 || $maxMinutes > 12;
        $periodDurationSeconds = $halfMode ? (20 * 60) : (12 * 60);

        return [$halfMode, $periodDurationSeconds];
    }

    /**
     * @return array<string,float>
     */
    private function resolveBaselineMap(string $sport, ?int $season): array
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

        return $this->stateBaselineService->getMap($sport, $season);
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
