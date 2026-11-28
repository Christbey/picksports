<?php

namespace App\Services\NFL;

use App\Services\Epa\StateBaselineService;
use App\Support\ScoreObservation;
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
    ): array {
        if ($plays->isEmpty()) {
            return [];
        }

        $rows = $plays->values()->all();
        $scoringDeltas = $this->buildScoringDeltas($rows);
        $realizedFuturePoints = $this->buildRealizedFuturePoints($rows, $homeTeamId, $awayTeamId, $scoringDeltas);
        $stateMap = $this->buildExpectedPointsStateMap($rows, $realizedFuturePoints);
        $baselineMap = $this->resolveBaselineMap($season);

        $results = [];
        $count = count($rows);

        for ($i = 0; $i < $count; $i++) {
            $play = $rows[$i];
            $playId = (int) $play->id;

            if (! $this->playDataService->isEpaEligiblePlay($play) || ! is_numeric($play->possession_team_id ?? null) || ! in_array((int) ($play->possession_team_id ?? 0), [$homeTeamId, $awayTeamId], true)) {
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
            $epBefore = $baselineMap[$stateKey] ?? $stateMap[$stateKey] ?? null;

            $nextEligible = $this->findNextEligiblePlay($rows, $i + 1);
            if ($nextEligible === null) {
                $epAfter = 0.0;
            } else {
                $nextStateKey = $this->stateKeyForPlay($nextEligible);
                $nextEp = $baselineMap[$nextStateKey] ?? $stateMap[$nextStateKey] ?? null;
                $nextOffenseTeamId = (int) $nextEligible->possession_team_id;
                $epAfter = $nextEp === null ? null : ($nextOffenseTeamId === $offenseTeamId ? $nextEp : -$nextEp);
            }

            $scoreDelta = $this->scoreDeltaForOffense(array_key_exists($playId, $scoringDeltas) ? $scoringDeltas[$playId] : [0, 0], $offenseTeamId, $homeTeamId, $awayTeamId);
            $epa = $scoreDelta === null || $epBefore === null || $epAfter === null ? null : $scoreDelta + ($epAfter - $epBefore);

            $results[$playId] = [
                'eligible' => true,
                'ep_before' => $epBefore === null ? null : round($epBefore, 3),
                'ep_after' => $epAfter === null ? null : round($epAfter, 3),
                'epa' => $epa === null ? null : round($epa, 3),
            ];
        }

        return $results;
    }

    /**
     * @param  array<int,object>  $rows
     * @return array<int,float>
     */
    private function buildRealizedFuturePoints(array $rows, int $homeTeamId, int $awayTeamId, array $scoringDeltas): array
    {
        $results = [];
        $count = count($rows);

        for ($i = 0; $i < $count; $i++) {
            $play = $rows[$i];
            $playId = (int) $play->id;

            if (! is_numeric($play->possession_team_id ?? null) || ! in_array((int) $play->possession_team_id, [$homeTeamId, $awayTeamId], true)) {
                $results[$playId] = null;

                continue;
            }

            $offenseTeamId = (int) $play->possession_team_id;
            $nextScore = 0.0;

            for ($j = $i; $j < $count; $j++) {
                if (! $this->sameScoringPeriod($play, $rows[$j])) {
                    break;
                }

                $delta = $this->scoreDeltaForOffense(array_key_exists((int) $rows[$j]->id, $scoringDeltas) ? $scoringDeltas[(int) $rows[$j]->id] : [0, 0], $offenseTeamId, $homeTeamId, $awayTeamId);
                if ($delta === null) {
                    $nextScore = null;
                    break;
                }
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
            $value = $realizedFuturePoints[(int) $play->id] ?? null;
            if ($value === null) {
                continue;
            }
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
            if (! $this->sameScoringPeriod($rows[$startIndex - 1], $play)) {
                return null;
            }
            if ($this->playDataService->isEpaEligiblePlay($play) && is_numeric($play->possession_team_id ?? null)) {
                return $play;
            }
        }

        return null;
    }

    /**
     * Attribute cumulative scoreboard changes to scoring events, never administrative rows.
     * The feed may already show a touchdown's score on the preceding timeout.
     *
     * @param  array<int,object>  $rows
     * @return array<int,array{int,int}>
     */
    private function buildScoringDeltas(array $rows): array
    {
        $deltas = [];
        $home = 0;
        $away = 0;

        foreach ($rows as $play) {
            if (! ($play->is_scoring_play ?? false)) {
                continue;
            }

            $nextHome = ScoreObservation::known($play->home_score ?? null);
            $nextAway = ScoreObservation::known($play->away_score ?? null);
            $deltas[(int) $play->id] = $nextHome === null || $nextAway === null || $home === null || $away === null
                ? null : [$nextHome - $home, $nextAway - $away];
            $home = $nextHome;
            $away = $nextAway;
        }

        return $deltas;
    }

    /** @param array{int,int} $delta */
    private function scoreDeltaForOffense(?array $delta, int $offenseTeamId, int $homeTeamId, int $awayTeamId): ?float
    {
        if ($delta === null) {
            return null;
        }
        if ($offenseTeamId === $homeTeamId) {
            return (float) ($delta[0] - $delta[1]);
        }

        if ($offenseTeamId === $awayTeamId) {
            return (float) ($delta[1] - $delta[0]);
        }

        return null;
    }

    private function sameScoringPeriod(object $before, object $after): bool
    {
        // Quarter breaks preserve possession value; halftime and regulation do not.
        $period = fn (object $play): int => (int) ($play->period ?? 1);
        $segment = fn (int $value): int => $value <= 2 ? 1 : ($value <= 4 ? 2 : 3);

        return $segment($period($before)) === $segment($period($after));
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
