<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\TeamMetric;
use App\Models\NFL\TeamMetricSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class TeamMetricSnapshotService
{
    public function __construct(
        protected HistoricalTeamMetricCalculator $historicalTeamMetricCalculator
    ) {}

    public function capture(int $season, CarbonInterface|string|null $capturedAt = null): int
    {
        $timestamp = $capturedAt !== null ? Carbon::parse($capturedAt) : now();

        $rows = TeamMetric::query()
            ->where('season', $season)
            ->get([
                'team_id',
                'season',
                'wins',
                'losses',
                'predictive_rating',
                'future_strength_of_schedule',
                'recent_form_rating',
                'injury_total_adjustment',
                'calculation_date',
            ])
            ->map(function (TeamMetric $metric) use ($timestamp): array {
                return [
                    'snapshot_key' => sha1(implode('|', [
                        (string) $metric->team_id,
                        (string) $metric->season,
                        $timestamp->toIso8601String(),
                    ])),
                    'team_id' => (int) $metric->team_id,
                    'season' => (int) $metric->season,
                    'wins' => (int) ($metric->wins ?? 0),
                    'losses' => (int) ($metric->losses ?? 0),
                    'predictive_rating' => $metric->predictive_rating,
                    'future_strength_of_schedule' => $metric->future_strength_of_schedule,
                    'recent_form_rating' => $metric->recent_form_rating,
                    'injury_total_adjustment' => $metric->injury_total_adjustment,
                    'calculation_date' => $metric->calculation_date,
                    'captured_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            })
            ->all();

        if ($rows === []) {
            return 0;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            TeamMetricSnapshot::query()->upsert($chunk, ['snapshot_key'], [
                'wins',
                'losses',
                'predictive_rating',
                'future_strength_of_schedule',
                'recent_form_rating',
                'injury_total_adjustment',
                'calculation_date',
                'captured_at',
                'updated_at',
            ]);
        }

        return count($rows);
    }

    /**
     * @param  array<int, string>  $dates
     */
    public function backfill(int $season, array $dates): int
    {
        $rows = [];

        foreach ($dates as $date) {
            $timestamp = Carbon::parse($date);
            $historicalRows = $this->historicalTeamMetricCalculator->calculateForDate($season, $timestamp);

            foreach ($historicalRows as $teamId => $row) {
                $rows[] = [
                    'snapshot_key' => sha1(implode('|', [
                        (string) $teamId,
                        (string) $season,
                        $timestamp->toIso8601String(),
                    ])),
                    'team_id' => (int) $teamId,
                    'season' => $season,
                    'wins' => (int) ($row['wins'] ?? 0),
                    'losses' => (int) ($row['losses'] ?? 0),
                    'predictive_rating' => $row['predictive_rating'],
                    'future_strength_of_schedule' => $row['future_strength_of_schedule'],
                    'recent_form_rating' => $row['recent_form_rating'],
                    'injury_total_adjustment' => $row['injury_total_adjustment'],
                    'calculation_date' => $row['calculation_date'],
                    'captured_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            TeamMetricSnapshot::query()->upsert($chunk, ['snapshot_key'], [
                'wins',
                'losses',
                'predictive_rating',
                'future_strength_of_schedule',
                'recent_form_rating',
                'injury_total_adjustment',
                'calculation_date',
                'captured_at',
                'updated_at',
            ]);
        }

        return count($rows);
    }
}
