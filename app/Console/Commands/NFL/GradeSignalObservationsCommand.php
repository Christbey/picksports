<?php

namespace App\Console\Commands\NFL;

use App\Models\NflSignalObservation;
use App\Services\NFL\NflSignalGradingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use InvalidArgumentException;

class GradeSignalObservationsCommand extends Command
{
    protected $signature = 'nfl:grade-signal-observations
        {--season= : Grade one NFL season}
        {--from-season= : First NFL season to grade}
        {--to-season= : Last NFL season to grade}
        {--signal-type= : Restrict grading to one signal type}
        {--signal-key= : Restrict grading to one signal key}
        {--limit= : Maximum pending observations to process this run}
        {--batch-size= : Observations loaded per database batch}
        {--refresh : Regrade finalized observations even when their sources are unchanged}';

    protected $description = 'Incrementally grade missing or stale NFL signal observations in bounded batches';

    public function handle(NflSignalGradingService $gradingService): int
    {
        try {
            [$fromSeason, $toSeason] = $this->seasonScope();
            [$limit, $batchSize] = $this->workLimits();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $query = $this->pendingQuery($fromSeason, $toSeason);
        $graded = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $lastId = 0;

        while ($graded + $skipped < $limit) {
            $remaining = $limit - ($graded + $skipped);
            $observations = (clone $query)
                ->where('nfl_signal_observations.id', '>', $lastId)
                ->orderBy('nfl_signal_observations.id')
                ->limit(min($batchSize, $remaining))
                ->get();

            if ($observations->isEmpty()) {
                break;
            }

            $gradingService->prepareBatch($observations);
            foreach ($observations as $observation) {
                $lastId = (int) $observation->id;
                $result = $this->option('refresh')
                    ? $gradingService->grade($observation)
                    : $gradingService->gradePending($observation);

                if ($result['skipped']) {
                    $skipped++;

                    continue;
                }

                $graded++;
                $created += $result['created'];
                $updated += $result['updated'];
            }
        }

        $hasMore = (clone $query)
            ->where('nfl_signal_observations.id', '>', $lastId)
            ->exists();

        $this->info(
            "Graded {$graded} NFL signal observation(s) from pending work; "
            ."{$created} grade row(s) created, {$updated} refreshed, {$skipped} skipped."
        );

        if ($hasMore) {
            $this->warn("The bounded run stopped at {$limit} observations; pending work remains for the next run.");
        }

        return self::SUCCESS;
    }

    private function pendingQuery(?int $fromSeason, ?int $toSeason): Builder
    {
        return NflSignalObservation::query()
            ->with(['featureSnapshot', 'grades'])
            ->when(
                $fromSeason !== null,
                fn (Builder $builder) => $builder->whereBetween('season', [$fromSeason, $toSeason])
            )
            ->when(
                $this->option('signal-type'),
                fn (Builder $builder) => $builder->where('signal_type', (string) $this->option('signal-type'))
            )
            ->when(
                $this->option('signal-key'),
                fn (Builder $builder) => $builder->where('signal_key', (string) $this->option('signal-key'))
            )
            ->whereHas('game', fn (Builder $game) => $game
                ->where('status', (string) config('nfl.statuses.final', 'STATUS_FINAL'))
                ->whereNotNull('home_score')
                ->whereNotNull('away_score'))
            ->when(! $this->option('refresh'), fn (Builder $builder) => $builder
                ->where(function (Builder $pending): void {
                    $pending
                        ->whereNotExists(function (QueryBuilder $state): void {
                            $state
                                ->selectRaw('1')
                                ->from('nfl_signal_grading_states as grading_state')
                                ->join('nfl_games as pending_result_games', 'pending_result_games.id', '=', 'nfl_signal_observations.game_id')
                                ->whereColumn(
                                    'grading_state.nfl_signal_observation_id',
                                    'nfl_signal_observations.id'
                                )
                                ->whereColumn('grading_state.home_score', 'pending_result_games.home_score')
                                ->whereColumn('grading_state.away_score', 'pending_result_games.away_score')
                                ->whereRaw(
                                    'grading_state.outcome_grade_count = '
                                    .'(SELECT COUNT(*) '
                                    .'FROM nfl_signal_grades AS result_grades '
                                    .'WHERE result_grades.nfl_signal_observation_id = nfl_signal_observations.id '
                                    .'AND result_grades.evaluation_source = ?)',
                                    ['outcome']
                                );
                        })
                        ->orWhereExists(function (QueryBuilder $settlements): void {
                            $settlements
                                ->selectRaw('1')
                                ->from('bet_decisions as pending_decisions')
                                ->join(
                                    'bet_settlements as pending_settlements',
                                    'pending_settlements.bet_decision_id',
                                    '=',
                                    'pending_decisions.id'
                                )
                                ->leftJoin(
                                    'nfl_signal_grades as persisted_settlement_grade',
                                    function (JoinClause $join): void {
                                        $join->on(
                                            'persisted_settlement_grade.nfl_signal_observation_id',
                                            '=',
                                            'nfl_signal_observations.id'
                                        )->on(
                                            'persisted_settlement_grade.bet_settlement_id',
                                            '=',
                                            'pending_settlements.id'
                                        );
                                    }
                                )
                                ->where('pending_decisions.sport', 'nfl')
                                ->whereColumn(
                                    'pending_decisions.prediction_feature_snapshot_id',
                                    'nfl_signal_observations.prediction_feature_snapshot_id'
                                )
                                ->where(function (QueryBuilder $stale): void {
                                    $stale
                                        ->whereNull('persisted_settlement_grade.id')
                                        ->orWhereColumn(
                                            'pending_settlements.updated_at',
                                            '>',
                                            'persisted_settlement_grade.updated_at'
                                        )
                                        ->orWhereColumn(
                                            'pending_decisions.updated_at',
                                            '>',
                                            'persisted_settlement_grade.updated_at'
                                        );
                                    // Source timestamps have second precision. Compare
                                    // settlement values too, so immediate corrections
                                    // cannot disappear behind an equal timestamp.
                                    $stale->orWhereRaw("CASE LOWER(pending_settlements.result_status) WHEN 'won' THEN 'win' WHEN 'lost' THEN 'loss' WHEN 'tie' THEN 'push' WHEN 'tied' THEN 'push' WHEN 'win' THEN 'win' WHEN 'loss' THEN 'loss' WHEN 'push' THEN 'push' ELSE 'unknown' END <> persisted_settlement_grade.result_status");
                                    foreach ([
                                        'pending_settlements.result_value' => 'persisted_settlement_grade.actual_value',
                                        'pending_settlements.profit_units' => 'persisted_settlement_grade.profit_units',
                                        'pending_settlements.clv' => 'persisted_settlement_grade.clv',
                                        'pending_decisions.price' => 'persisted_settlement_grade.price',
                                        'pending_decisions.line' => 'persisted_settlement_grade.line',
                                    ] as $source => $persisted) {
                                        $stale->orWhereColumn($source, '<>', $persisted)
                                            ->orWhere(fn (QueryBuilder $null) => $null->whereNull($source)->whereNotNull($persisted))
                                            ->orWhere(fn (QueryBuilder $null) => $null->whereNotNull($source)->whereNull($persisted));
                                    }
                                });
                        });
                }));
    }

    /**
     * @return array{0:int,1:int}
     */
    private function workLimits(): array
    {
        $configuredLimit = max(1, (int) config('nfl.signal_grading.max_per_run', 1000));
        $hardLimit = max(1, (int) config('nfl.signal_grading.hard_max_per_run', 5000));
        $requestedLimit = $this->option('limit');

        if ($requestedLimit !== null && (int) $requestedLimit < 1) {
            throw new InvalidArgumentException('--limit must be at least 1.');
        }

        $limit = min($hardLimit, (int) ($requestedLimit ?? $configuredLimit));
        $configuredBatchSize = max(1, (int) config('nfl.signal_grading.batch_size', 250));
        $requestedBatchSize = $this->option('batch-size');

        if ($requestedBatchSize !== null && (int) $requestedBatchSize < 1) {
            throw new InvalidArgumentException('--batch-size must be at least 1.');
        }

        $batchSize = min($limit, 500, (int) ($requestedBatchSize ?? $configuredBatchSize));

        return [$limit, $batchSize];
    }

    /**
     * @return array{0:?int,1:?int}
     */
    private function seasonScope(): array
    {
        $season = $this->option('season');
        $fromSeason = $this->option('from-season');
        $toSeason = $this->option('to-season');

        if ($season !== null && ($fromSeason !== null || $toSeason !== null)) {
            throw new InvalidArgumentException('Use either --season or --from-season/--to-season, not both.');
        }

        if ($season !== null) {
            return [(int) $season, (int) $season];
        }

        if ($fromSeason === null && $toSeason === null) {
            return [null, null];
        }

        $from = (int) ($fromSeason ?? $toSeason);
        $to = (int) ($toSeason ?? $fromSeason);
        if ($from > $to) {
            throw new InvalidArgumentException('--from-season must be less than or equal to --to-season.');
        }

        return [$from, $to];
    }
}
