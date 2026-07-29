<?php

namespace App\Services\NFL;

use App\Models\NflSignalObservation;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NflSignalGradeReportService
{
    /**
     * @param  array{
     *     from_season?:?int,
     *     to_season?:?int,
     *     signal_type?:?string,
     *     signal_key?:?string,
     *     pregame_safe?:bool,
     *     limit?:int
     * }  $filters
     * @return array{signals:list<array<string,mixed>>,windows:list<array<string,mixed>>}
     */
    public function report(array $filters = []): array
    {
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $summaryRows = $this->aggregateQuery($filters)
            ->select([
                'nfl_signal_observations.signal_type',
                'nfl_signal_observations.signal_key',
            ])
            ->selectRaw('COUNT(DISTINCT nfl_signal_observations.id) as observation_count')
            ->addSelect($this->metricSelects())
            ->groupBy([
                'nfl_signal_observations.signal_type',
                'nfl_signal_observations.signal_key',
            ])
            ->orderByDesc('winner_sample')
            ->orderByDesc('observation_count')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => $this->formatMetrics($row))
            ->values();

        if ($summaryRows->isEmpty()) {
            return ['signals' => [], 'windows' => []];
        }

        $windows = $this->windowRows($filters, $summaryRows);
        $windowsBySignal = $windows->groupBy(
            fn (array $row): string => $row['signal_type'].'|'.$row['signal_key']
        );

        $signals = $summaryRows
            ->map(function (array $row) use ($windowsBySignal): array {
                $signalWindows = $windowsBySignal->get($row['signal_type'].'|'.$row['signal_key'], collect());
                $winnerAccuracies = $signalWindows
                    ->pluck('winner_accuracy')
                    ->filter(fn (mixed $value): bool => $value !== null)
                    ->map(fn (mixed $value): float => (float) $value)
                    ->values();
                $roiWindows = $signalWindows
                    ->filter(fn (array $window): bool => $window['roi'] !== null);

                return [
                    ...$row,
                    'window_count' => $signalWindows->count(),
                    'winner_accuracy_range' => $winnerAccuracies->isEmpty()
                        ? null
                        : round((float) $winnerAccuracies->max() - (float) $winnerAccuracies->min(), 4),
                    'positive_roi_windows' => $roiWindows->where('roi', '>', 0)->count(),
                    'roi_window_count' => $roiWindows->count(),
                ];
            })
            ->values()
            ->all();

        return [
            'signals' => $signals,
            'windows' => $windows->values()->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function aggregateQuery(array $filters): Builder
    {
        return $this->applyFilters(
            NflSignalObservation::query()
                ->leftJoin(
                    'nfl_signal_grades',
                    'nfl_signal_grades.nfl_signal_observation_id',
                    '=',
                    'nfl_signal_observations.id'
                ),
            $filters
        );
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                isset($filters['from_season']),
                fn (Builder $builder): Builder => $builder->where(
                    'nfl_signal_observations.season',
                    '>=',
                    (int) $filters['from_season']
                )
            )
            ->when(
                isset($filters['to_season']),
                fn (Builder $builder): Builder => $builder->where(
                    'nfl_signal_observations.season',
                    '<=',
                    (int) $filters['to_season']
                )
            )
            ->when(
                ! empty($filters['signal_type']),
                fn (Builder $builder): Builder => $builder->where(
                    'nfl_signal_observations.signal_type',
                    (string) $filters['signal_type']
                )
            )
            ->when(
                ! empty($filters['signal_key']),
                fn (Builder $builder): Builder => $builder->where(
                    'nfl_signal_observations.signal_key',
                    (string) $filters['signal_key']
                )
            )
            ->when(
                (bool) ($filters['pregame_safe'] ?? true),
                fn (Builder $builder): Builder => $builder->where(
                    'nfl_signal_observations.pregame_safe',
                    true
                )
            );
    }

    /**
     * @return list<Expression>
     */
    private function metricSelects(): array
    {
        return [
            DB::raw($this->countMetric('outcome', 'winner').' AS winner_sample'),
            DB::raw($this->winMetric('outcome', 'winner').' AS winner_wins'),
            DB::raw($this->countMetric('outcome', 'spread').' AS ats_sample'),
            DB::raw($this->winMetric('outcome', 'spread').' AS ats_wins'),
            DB::raw($this->countMetric('outcome', 'total').' AS total_sample'),
            DB::raw($this->winMetric('outcome', 'total').' AS total_wins'),
            DB::raw(
                "SUM(CASE WHEN nfl_signal_grades.evaluation_source = 'settlement'
                    AND nfl_signal_grades.is_actual_bet = 1 THEN 1 ELSE 0 END) AS settlement_sample"
            ),
            DB::raw(
                "SUM(CASE WHEN nfl_signal_grades.evaluation_source = 'settlement'
                    AND nfl_signal_grades.is_actual_bet = 1
                    THEN nfl_signal_grades.profit_units ELSE 0 END) AS profit_units"
            ),
            DB::raw(
                "SUM(CASE WHEN nfl_signal_grades.evaluation_source = 'settlement'
                    AND nfl_signal_grades.shadow_profit_units IS NOT NULL
                    THEN 1 ELSE 0 END) AS shadow_settlement_sample"
            ),
            DB::raw(
                "SUM(CASE WHEN nfl_signal_grades.evaluation_source = 'settlement'
                    THEN nfl_signal_grades.shadow_profit_units ELSE 0 END) AS shadow_profit_units"
            ),
            DB::raw(
                "AVG(CASE WHEN nfl_signal_grades.evaluation_source = 'settlement'
                    THEN nfl_signal_grades.clv END) AS avg_clv"
            ),
            DB::raw(
                "AVG(CASE WHEN nfl_signal_grades.evaluation_source = 'outcome'
                    AND nfl_signal_grades.market_type = 'winner'
                    THEN nfl_signal_grades.brier_score END) AS avg_brier_score"
            ),
            DB::raw(
                "AVG(CASE WHEN nfl_signal_grades.evaluation_source = 'outcome'
                    AND nfl_signal_grades.market_type = 'winner'
                    THEN nfl_signal_grades.calibration_lift END) AS avg_calibration_lift"
            ),
            DB::raw(
                "AVG(CASE WHEN nfl_signal_grades.evaluation_source = 'outcome'
                    AND nfl_signal_grades.market_type = 'spread'
                    THEN nfl_signal_grades.absolute_error END) AS avg_spread_error"
            ),
            DB::raw(
                "AVG(CASE WHEN nfl_signal_grades.evaluation_source = 'outcome'
                    AND nfl_signal_grades.market_type = 'spread'
                    THEN nfl_signal_grades.error_lift END) AS avg_spread_error_lift"
            ),
            DB::raw(
                "AVG(CASE WHEN nfl_signal_grades.evaluation_source = 'outcome'
                    AND nfl_signal_grades.market_type = 'total'
                    THEN nfl_signal_grades.absolute_error END) AS avg_total_error"
            ),
            DB::raw(
                "AVG(CASE WHEN nfl_signal_grades.evaluation_source = 'outcome'
                    AND nfl_signal_grades.market_type = 'total'
                    THEN nfl_signal_grades.error_lift END) AS avg_total_error_lift"
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @param  Collection<int,array<string,mixed>>  $signals
     * @return Collection<int,array<string,mixed>>
     */
    private function windowRows(array $filters, Collection $signals): Collection
    {
        $query = $this->aggregateQuery($filters)
            ->where(function (Builder $builder) use ($signals): void {
                foreach ($signals as $signal) {
                    $builder->orWhere(function (Builder $signalQuery) use ($signal): void {
                        $signalQuery
                            ->where('nfl_signal_observations.signal_type', $signal['signal_type'])
                            ->where('nfl_signal_observations.signal_key', $signal['signal_key']);
                    });
                }
            })
            ->select([
                'nfl_signal_observations.signal_type',
                'nfl_signal_observations.signal_key',
                'nfl_signal_observations.season',
            ])
            ->selectRaw('COUNT(DISTINCT nfl_signal_observations.id) as observation_count')
            ->addSelect($this->metricSelects())
            ->groupBy([
                'nfl_signal_observations.signal_type',
                'nfl_signal_observations.signal_key',
                'nfl_signal_observations.season',
            ])
            ->orderBy('nfl_signal_observations.season')
            ->get();

        return $query
            ->map(function (object $row): array {
                return [
                    ...$this->formatMetrics($row),
                    'season' => (int) $row->season,
                    'window' => 'season:'.$row->season,
                ];
            })
            ->filter(fn (array $row): bool => (
                $row['winner_sample']
                + $row['ats_sample']
                + $row['total_sample']
                + $row['settlement_sample']
                + $row['shadow_settlement_sample']
            ) > 0)
            ->values();
    }

    /**
     * @return array<string,mixed>
     */
    private function formatMetrics(object $row): array
    {
        $winnerSample = (int) ($row->winner_sample ?? 0);
        $atsSample = (int) ($row->ats_sample ?? 0);
        $totalSample = (int) ($row->total_sample ?? 0);
        $settlementSample = (int) ($row->settlement_sample ?? 0);
        $shadowSettlementSample = (int) ($row->shadow_settlement_sample ?? 0);

        return [
            'signal_type' => (string) $row->signal_type,
            'signal_key' => (string) $row->signal_key,
            'observation_count' => (int) $row->observation_count,
            'winner_sample' => $winnerSample,
            'winner_accuracy' => $this->rate((int) ($row->winner_wins ?? 0), $winnerSample),
            'ats_sample' => $atsSample,
            'ats_hit_rate' => $this->rate((int) ($row->ats_wins ?? 0), $atsSample),
            'total_sample' => $totalSample,
            'total_hit_rate' => $this->rate((int) ($row->total_wins ?? 0), $totalSample),
            'settlement_sample' => $settlementSample,
            'roi' => $settlementSample > 0
                ? round((float) $row->profit_units / $settlementSample, 6)
                : null,
            'shadow_settlement_sample' => $shadowSettlementSample,
            'shadow_roi' => $shadowSettlementSample > 0
                ? round((float) $row->shadow_profit_units / $shadowSettlementSample, 6)
                : null,
            'avg_clv' => $this->nullableRound($row->avg_clv ?? null),
            'avg_brier_score' => $this->nullableRound($row->avg_brier_score ?? null),
            'avg_calibration_lift' => $this->nullableRound($row->avg_calibration_lift ?? null),
            'avg_spread_error' => $this->nullableRound($row->avg_spread_error ?? null),
            'avg_spread_error_lift' => $this->nullableRound($row->avg_spread_error_lift ?? null),
            'avg_total_error' => $this->nullableRound($row->avg_total_error ?? null),
            'avg_total_error_lift' => $this->nullableRound($row->avg_total_error_lift ?? null),
        ];
    }

    private function countMetric(string $source, string $marketType): string
    {
        return "SUM(CASE WHEN nfl_signal_grades.evaluation_source = '{$source}'
            AND nfl_signal_grades.market_type = '{$marketType}'
            AND nfl_signal_grades.result_status IN ('win', 'loss')
            THEN 1 ELSE 0 END)";
    }

    private function winMetric(string $source, string $marketType): string
    {
        return "SUM(CASE WHEN nfl_signal_grades.evaluation_source = '{$source}'
            AND nfl_signal_grades.market_type = '{$marketType}'
            AND nfl_signal_grades.result_status = 'win'
            THEN 1 ELSE 0 END)";
    }

    private function rate(int $wins, int $sample): ?float
    {
        return $sample > 0 ? round($wins / $sample, 6) : null;
    }

    private function nullableRound(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 6) : null;
    }
}
