<?php

namespace App\Services\NFL;

use App\Models\NFL\TeamMetric;
use App\Models\NFL\TeamMetricSnapshot;
use App\Support\Sports\LegacyTimestampCarrier;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class TeamMetricSnapshotService
{
    public function __construct(
        protected HistoricalTeamMetricCalculator $historicalTeamMetricCalculator
    ) {}

    public function capture(int $season, CarbonInterface|string|null $capturedAt = null): int
    {
        $timestamp = $capturedAt !== null ? Carbon::parse($capturedAt) : now()->startOfSecond();
        $this->assertCutoffPrecision($timestamp);

        $metrics = TeamMetric::query()->where('season', $season)
            ->where('season_type', (string) config('nfl.season.types.regular', 2))
            ->orderByDesc('calculation_date')->orderByDesc('id')->get()->unique('team_id');
        $observed = now()->utc();
        $rows = [];
        foreach ($metrics as $metric) {
            $row = TeamMetricSnapshotObservation::values($metric->getAttributes());
            $rows[] = $this->prepare($row, $timestamp, $observed, TeamMetricSnapshotObservation::CURRENT,
                ['metric_id' => (int) $metric->id, 'season_type' => (string) $metric->season_type]);
        }

        return $this->persist($rows);
    }

    /**
     * @param  array<int, string>  $dates
     */
    public function backfill(int $season, array $dates, bool $reconstructScorePriors = false): int
    {
        $rows = [];
        $observed = now()->utc();
        foreach ($dates as $date) {
            $timestamp = Carbon::parse($date)->setTimezone(config('app.timezone'));
            $this->assertCutoffPrecision($timestamp);
            $historicalRows = $this->historicalTeamMetricCalculator->calculateForDate(
                $season, $timestamp, pointInTime: true, reconstructScorePriors: $reconstructScorePriors
            );
            foreach ($historicalRows as $teamId => $row) {
                $row = TeamMetricSnapshotObservation::values(['team_id' => $teamId, 'season' => $season] + $row);
                $rows[] = $this->prepare($row, $timestamp, $observed, TeamMetricSnapshotObservation::RECONSTRUCTED,
                    ['calculator' => HistoricalTeamMetricCalculator::class, 'reconstruct_score_priors' => $reconstructScorePriors]);
            }
        }

        return $this->persist($rows);
    }

    private function assertCutoffPrecision(CarbonInterface $cutoff): void
    {
        if ($cutoff->format('u') !== '000000') {
            throw new \DomainException('Explicit snapshot cutoff must use whole-second storage precision.');
        }
    }

    private function prepare(array $row, CarbonInterface $cutoff, CarbonInterface $observed, string $mode, array $source): array
    {
        $this->assertCutoffPrecision($cutoff);
        $cutoffString = $cutoff->copy()->utc()->toIso8601String();
        $row['snapshot_key'] = TeamMetricSnapshotObservation::key($row, $mode, $cutoffString);
        $carrier = LegacyTimestampCarrier::forInstant($cutoff);
        if ($carrier === null) {
            throw new \DomainException('Snapshot cutoff cannot be represented by its storage clock.');
        }
        $row['captured_at'] = $carrier;
        $row['observation'] = TeamMetricSnapshotObservation::receipt($row, $mode, $source, $cutoffString, $observed->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'));

        return $row;
    }

    private function persist(array $rows): int
    {
        $model = new TeamMetricSnapshot;
        $connection = $model->getConnection();

        return $connection->transaction(function () use ($rows, $model): int {
            $expected = [];
            foreach ($rows as $row) {
                $existing = $model->newQuery()->where('snapshot_key', $row['snapshot_key'])->lockForUpdate()->first();
                if ($existing !== null) {
                    $receipt = TeamMetricSnapshotObservation::lineage($existing);
                    if (($receipt['mode'] ?? null) !== $row['observation']['mode']
                        || ! TeamMetricSnapshotObservation::sameDescriptor($receipt['source'] ?? [], $row['observation']['source'])
                        || ($receipt['values_hash'] ?? null) !== $row['observation']['values_hash']) {
                        throw new \DomainException('Snapshot receipt already exists with different inputs.');
                    }
                    $expected[] = [$existing->getKey(), $row, $existing->observation];

                    continue;
                }
                $snapshot = $model->newInstance($row);
                $snapshot->setConnection($model->getConnectionName());
                if ($snapshot->save() !== true || ! $snapshot->exists) {
                    throw new \DomainException('Snapshot receipt was not persisted.');
                }
                $actual = $model->newQuery()->whereKey($snapshot->getKey())->first();
                if ($actual === null || $actual->snapshot_key !== $row['snapshot_key']
                    || TeamMetricSnapshotObservation::hash($actual->getAttributes()) !== $row['observation']['values_hash']
                    || ! TeamMetricSnapshotObservation::sameDescriptor($actual->observation ?? [], $row['observation'])) {
                    throw new \DomainException('Snapshot receipt postimage mismatch.');
                }
                $expected[] = [$actual->getKey(), $row, $actual->observation];
            }
            foreach ($expected as [$id, $row, $observation]) {
                $actual = $model->newQuery()->whereKey($id)->first();
                if ($actual === null || $actual->snapshot_key !== $row['snapshot_key']
                    || $actual->getRawOriginal('captured_at') !== $row['captured_at']->format('Y-m-d H:i:s')
                    || TeamMetricSnapshotObservation::hash($actual->getAttributes()) !== $row['observation']['values_hash']
                    || ! TeamMetricSnapshotObservation::sameDescriptor($actual->observation ?? [], $observation)) {
                    throw new \DomainException('Snapshot receipt final postimage mismatch.');
                }
            }

            return count($rows);
        });
    }
}
