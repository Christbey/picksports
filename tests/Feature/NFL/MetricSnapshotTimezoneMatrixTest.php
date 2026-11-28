<?php

use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Models\NFL\TeamMetricSnapshot;
use App\Services\NFL\TeamFuturesProjectionService;
use App\Services\NFL\TeamMetricSnapshotObservation;
use App\Services\NFL\TeamMetricSnapshotService;
use App\Support\Sports\LegacyTimestampCarrier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

it('round trips actual configured UTC and Chicago snapshot clocks without changing instants', function (string $timezone, string $instant, string $wall) {
    $previousTimezone = date_default_timezone_get();
    $previousConfig = config('app.timezone');
    $previousClock = Carbon::getTestNow();
    Http::preventStrayRequests();
    try {
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
        Carbon::setTestNow(CarbonImmutable::parse($instant));
        $team = Team::factory()->create();
        TeamMetric::query()->create([
            'team_id' => $team->id, 'season' => 2026, 'season_type' => '2',
            'wins' => 0, 'losses' => 0, 'predictive_rating' => 1500,
            'future_strength_of_schedule' => 1500, 'recent_form_rating' => 0,
            'injury_total_adjustment' => 0, 'calculation_date' => now()->toDateString(),
        ]);
        expect(app(TeamMetricSnapshotService::class)->capture(2026, now()))->toBe(1);
        $snapshot = TeamMetricSnapshot::query()->firstOrFail();
        expect($snapshot->getRawOriginal('captured_at'))->toBe($wall)
            ->and($snapshot->captured_at->eq(CarbonImmutable::parse($instant)))->toBeTrue()
            ->and($snapshot->observation['storage_timezone'])->toBe($timezone)
            ->and($snapshot->observation['cutoff_at'])->toBe(CarbonImmutable::parse($instant)->utc()->toIso8601String())
            ->and($snapshot->observation['observed_at'])->toBe(CarbonImmutable::parse($instant)->utc()->format('Y-m-d\TH:i:s.u\Z'))
            ->and(TeamMetricSnapshotObservation::lineage($snapshot)['mode'])->toBe('current_capture');
        $reader = new ReflectionMethod(TeamFuturesProjectionService::class, 'metricsByTeam');
        $rows = $reader->invoke(app(TeamFuturesProjectionService::class), 2026, now(), true, true);
        expect($rows[$team->id]['_metric_evidence']['mode'])->toBe('current_capture');
        Http::assertNothingSent();
    } finally {
        Carbon::setTestNow($previousClock);
        config(['app.timezone' => $previousConfig]);
        date_default_timezone_set($previousTimezone);
    }
})->with([
    ['UTC', '2026-11-28T12:00:00Z', '2026-11-28 12:00:00'],
    ['America/Chicago', '2026-11-28T12:00:00Z', '2026-11-28 06:00:00'],
    ['America/Chicago', '2026-11-28T06:00:00Z', '2026-11-28 00:00:00'],
    ['America/Chicago', '2026-07-01T12:00:00Z', '2026-07-01 07:00:00'],
    ['America/Chicago', '2026-03-08T07:59:59Z', '2026-03-08 01:59:59'],
    ['America/Chicago', '2026-03-08T08:00:00Z', '2026-03-08 03:00:00'],
    ['America/Chicago', '2026-11-01T06:30:00Z', '2026-11-01 01:30:00'],
]);

it('refuses the later repeated Chicago hour when the existing wall carrier cannot round trip its instant', function () {
    $previousTimezone = date_default_timezone_get();
    $previousConfig = config('app.timezone');
    $previousClock = Carbon::getTestNow();
    Http::preventStrayRequests();
    try {
        config(['app.timezone' => 'America/Chicago']);
        date_default_timezone_set('America/Chicago');
        $instant = CarbonImmutable::parse('2026-11-01T07:30:00Z');
        Carbon::setTestNow($instant);
        $team = Team::factory()->create();
        $metric = TeamMetric::query()->create([
            'team_id' => $team->id, 'season' => 2026, 'season_type' => '2',
            'wins' => 0, 'losses' => 0, 'predictive_rating' => 1500,
            'future_strength_of_schedule' => 1500, 'recent_form_rating' => 0,
            'injury_total_adjustment' => 0, 'calculation_date' => '2026-11-01',
        ]);
        $before = $metric->fresh()->getAttributes();
        expect(LegacyTimestampCarrier::forInstant($instant))->toBeNull()
            ->and(fn () => app(TeamMetricSnapshotService::class)->capture(2026, $instant))->toThrow(DomainException::class)
            ->and(TeamMetricSnapshot::query()->count())->toBe(0)
            ->and($metric->fresh()->getAttributes())->toBe($before);
        Http::assertNothingSent();
    } finally {
        Carbon::setTestNow($previousClock);
        config(['app.timezone' => $previousConfig]);
        date_default_timezone_set($previousTimezone);
    }
});
