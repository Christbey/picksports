<?php

namespace App\Services\CFB;

use App\Services\Epa\StateBaselineService;
use App\Support\CFB\ObservedTerminalBoundary;
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
            $epBefore = $baselineMap[$stateKey] ?? null;

            $period = (int) ($play->period ?? 0);
            $end = data_get($play->source_state ?? [], 'end');
            $next = $rows[$i + 1] ?? null;
            $observedEnd = $next !== null
                && ObservedTerminalBoundary::forHalf($next, $period, $homeTeamId, $awayTeamId)
                && ScoreObservation::known($play->home_score ?? null) === ScoreObservation::known($next->home_score ?? null)
                && ScoreObservation::known($play->away_score ?? null) === ScoreObservation::known($next->away_score ?? null);
            $terminal = (bool) ($play->is_scoring_play ?? false) || $observedEnd;
            if ($terminal) {
                $epAfter = 0.0;
            } elseif (is_array($end) && isset($end['down'], $end['distance'], $end['yardsToEndzone']) && $end['down'] >= 1 && $end['down'] <= 4) {
                $state = (object) ['down' => $end['down'], 'distance' => $end['distance'], 'yards_to_endzone' => $end['yardsToEndzone']];
                $value = $baselineMap[$this->stateKeyForPlay($state)] ?? null;
                $startTeam = data_get($play->source_state, 'start.team.id') ?? data_get($play->source_state, 'start.team.$ref');
                $endTeam = data_get($play->source_state, 'end.team.id') ?? data_get($play->source_state, 'end.team.$ref');
                $same = $startTeam !== null && $endTeam !== null ? $startTeam === $endTeam : null;
                $epAfter = $value === null || $same === null ? null : ($same ? $value : -$value);
            } else {
                // Never jump over a punt, field goal, penalty, score or possession to find a convenient state.
                $next = $rows[$i + 1] ?? null;
                $nextPeriod = (int) ($next->period ?? 0);
                $sameHalf = $period > 0 && $nextPeriod > 0
                    && ($period <= 2 ? 1 : ($period <= 4 ? 2 : $period)) === ($nextPeriod <= 2 ? 1 : ($nextPeriod <= 4 ? 2 : $nextPeriod));
                if ($next && $sameHalf && $this->playDataService->isEpaEligiblePlay($next) && is_numeric($next->possession_team_id)) {
                    $value = $baselineMap[$this->stateKeyForPlay($next)] ?? null;
                    $epAfter = $value === null ? null : ((int) $next->possession_team_id === $offenseTeamId ? $value : -$value);
                } else {
                    $epAfter = null;
                }
            }

            $scoreDelta = $this->scoreDeltaForOffense($rows, $i, $offenseTeamId, $homeTeamId, $awayTeamId);
            if ($scoreDelta === null || $epBefore === null || $epAfter === null) {
                $results[$playId] = ['eligible' => true, 'ep_before' => $epBefore, 'ep_after' => $epAfter, 'epa' => null];

                continue;
            }
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
            $enabled = (bool) config('cfb.scoring.past_epa_baseline', true);
        } catch (\Throwable) {
            return [];
        }

        if (! $enabled) {
            return [];
        }

        return $this->stateBaselineService->getMap('cfb', $season);
    }
}
