<?php

namespace App\Services\NBA;

use App\Services\CBB\ObservedTerminalEpaContext;
use App\Services\CBB\PinnedEpaBaseline;
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
        ?int $season = null,
        string $sport = 'nba',
        ?ObservedTerminalEpaContext $observedTerminal = null,
        ?PinnedEpaBaseline $pinnedBaseline = null
    ): array {
        if ($plays->isEmpty()) {
            return [];
        }

        if ($pinnedBaseline !== null && ($sport !== 'cbb' || $season !== $pinnedBaseline->season
            || $plays->contains(fn ($play) => (int) $play->game_id !== $pinnedBaseline->gameId))) {
            throw new \RuntimeException('Pinned CBB baseline does not match calculator inputs.');
        }
        if ($observedTerminal !== null && $pinnedBaseline !== null && $observedTerminal->baselineSourceHash !== $pinnedBaseline->sourceHash) {
            throw new \RuntimeException('CBB terminal and baseline source bindings disagree.');
        }
        if ($observedTerminal !== null) {
            if ($sport !== 'cbb') {
                throw new \RuntimeException('Observed CBB terminal policy cannot be used for another sport.');
            }
            $observedTerminal->assertCalculatorInputs($plays, $homeTeamId, $awayTeamId, $season);
        }
        $rows = $plays->values()->all();
        [$halfMode, $periodDurationSeconds] = $this->derivePeriodContext($rows);
        $realizedFuturePoints = $this->buildRealizedFuturePoints($rows, $homeTeamId, $awayTeamId, $observedTerminal !== null);
        $stateMap = $this->buildExpectedPointsStateMap($rows, $realizedFuturePoints, $halfMode, $periodDurationSeconds);
        $baselineMap = $pinnedBaseline?->map ?? $observedTerminal?->baselineMap ?? $this->resolveBaselineMap($sport, $season);

        $results = [];
        $count = count($rows);

        for ($i = 0; $i < $count; $i++) {
            $play = $rows[$i];
            $playId = (int) $play->id;

            if (! $this->playDataService->isEpaEligiblePlay($play) || ! in_array((int) ($play->possession_team_id ?? 0), [$homeTeamId, $awayTeamId], true)) {
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
            $epBefore = $baselineMap[$stateKey] ?? $stateMap[$stateKey] ?? null;

            $nextEligible = $this->findNextEligiblePlay($rows, $i + 1);
            if ($nextEligible === null) {
                $epAfter = $observedTerminal === null ? null : 0.0;
            } else {
                $nextKey = $this->stateKeyForPlay($nextEligible, $halfMode, $periodDurationSeconds);
                $nextEp = $baselineMap[$nextKey] ?? $stateMap[$nextKey] ?? null;
                $nextOffenseTeamId = (int) $nextEligible->possession_team_id;
                $epAfter = $nextEp === null ? null : ($nextOffenseTeamId === $offenseTeamId ? $nextEp : -$nextEp);
            }

            $scoreDelta = $this->scoreDeltaForOffense($rows, $i, $offenseTeamId, $homeTeamId, $awayTeamId);
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
     * @return array<int,?float>
     */
    private function buildRealizedFuturePoints(array $rows, int $homeTeamId, int $awayTeamId, bool $observedTerminal = false): array
    {
        $results = [];
        $nextHomePoints = $observedTerminal ? 0.0 : null;
        // Each adjacent scoreboard pair is inspected once. Missing observations
        // break the label chain; absence of a future event is not measured zero.
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $play = $rows[$i];
            $offense = $play->possession_team_id ?? null;
            $results[(int) $play->id] = ! is_numeric($offense) || $nextHomePoints === null
                ? null : ((int) $offense === $homeTeamId ? $nextHomePoints : ((int) $offense === $awayTeamId ? -$nextHomePoints : null));
            if ($i === 0) {
                continue;
            }
            $delta = $this->scoreDeltaBetween($rows[$i - 1], $play, $homeTeamId, $homeTeamId, $awayTeamId);
            if ($delta === null) {
                $nextHomePoints = null;
            } elseif (abs($delta) > 0.0001) {
                $nextHomePoints = $delta;
            }
        }

        return $results;
    }

    /**
     * @param  array<int,object>  $rows
     * @param  array<int,?float>  $realizedFuturePoints
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
            if (ScoreObservation::known($play->home_score ?? null) === null || ScoreObservation::known($play->away_score ?? null) === null) {
                return null;
            }
            if ($this->playDataService->isEpaEligiblePlay($play)) {
                return $play;
            }
        }

        return null;
    }

    /**
     * @param  array<int,object>  $rows
     */
    private function scoreDeltaForOffense(array $rows, int $index, int $offenseTeamId, int $homeTeamId, int $awayTeamId): ?float
    {
        if ($index <= 0) {
            return null;
        }

        return $this->scoreDeltaBetween($rows[$index - 1], $rows[$index], $offenseTeamId, $homeTeamId, $awayTeamId);
    }

    private function scoreDeltaBetween(object $before, object $after, int $offenseTeamId, int $homeTeamId, int $awayTeamId): ?float
    {
        $homeBefore = ScoreObservation::known($before->home_score ?? null);
        $awayBefore = ScoreObservation::known($before->away_score ?? null);
        $homeAfter = ScoreObservation::known($after->home_score ?? null);
        $awayAfter = ScoreObservation::known($after->away_score ?? null);
        if ($homeBefore === null || $awayBefore === null || $homeAfter === null || $awayAfter === null) {
            return null;
        }

        $homeDelta = $homeAfter - $homeBefore;
        $awayDelta = $awayAfter - $awayBefore;

        if ($offenseTeamId === $homeTeamId) {
            return (float) ($homeDelta - $awayDelta);
        }

        if ($offenseTeamId === $awayTeamId) {
            return (float) ($awayDelta - $homeDelta);
        }

        return null;
    }

    public function stateKeyForPlay(object $play, bool $halfMode, int $periodDurationSeconds): ?string
    {
        $period = is_numeric($play->period ?? null) ? (int) $play->period : 0;
        $clock = (string) ($play->clock ?? '');
        $home = ScoreObservation::known($play->home_score ?? null);
        $away = ScoreObservation::known($play->away_score ?? null);
        if ($home === null || $away === null) {
            return null;
        }

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
