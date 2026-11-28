<?php

use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Models\NFL\TeamMetricSnapshot;
use App\Services\NFL\HistoricalTeamMetricCalculator;
use App\Services\NFL\TeamFuturesProjectionService;
use App\Services\NFL\TeamMetricSnapshotObservation;
use App\Services\NFL\TeamMetricSnapshotService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

$metricCaptureClockState = null;

beforeEach(function () use (&$metricCaptureClockState) {
    $metricCaptureClockState = [date_default_timezone_get(), config('app.timezone'), Carbon::getTestNow()];
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    Http::preventStrayRequests();
    $this->travelTo(Carbon::parse('2026-11-28 12:00:00', 'UTC'));
});

afterEach(function () use (&$metricCaptureClockState) {
    Carbon::setTestNow($metricCaptureClockState[2]);
    config(['app.timezone' => $metricCaptureClockState[1]]);
    date_default_timezone_set($metricCaptureClockState[0]);
});

function metricCaptureClockFixture(): TeamMetric
{
    $team = Team::factory()->create();

    return TeamMetric::query()->create([
        'team_id' => $team->id, 'season' => 2026, 'season_type' => '2',
        'wins' => 9, 'losses' => 3, 'predictive_rating' => 1700,
        'future_strength_of_schedule' => 1500, 'recent_form_rating' => 0.5,
        'injury_total_adjustment' => 0, 'calculation_date' => '2026-10-01',
    ]);
}

it('does not admit a newly observed current metric as an old point in time snapshot', function () {
    $metric = metricCaptureClockFixture();
    $cutoff = Carbon::parse('2026-10-02 12:00:00', 'UTC');
    expect($metric->fresh()->updated_at->gt($cutoff))->toBeTrue();
    $exit = Artisan::call('nfl:snapshot-team-metrics', ['--season' => 2026, '--date' => $cutoff->toIso8601String()]);
    $stored = TeamMetricSnapshot::query()->first();
    expect($stored)->not->toBeNull()
        ->and($stored->wins)->toBe(9)
        ->and($stored->captured_at->eq($cutoff))->toBeTrue()
        ->and($exit)->toBe(0);
    $method = new ReflectionMethod(TeamFuturesProjectionService::class, 'metricsByTeam');
    $selected = $method->invoke(app(TeamFuturesProjectionService::class), 2026, $cutoff, true, true);
    expect($selected)->not->toHaveKey($metric->team_id);
});

it('does not replace an earlier cutoff snapshot with a later current metric correction', function () {
    $metric = metricCaptureClockFixture();
    $args = ['--season' => 2026, '--date' => '2026-10-02T12:00:00Z'];
    Artisan::call('nfl:snapshot-team-metrics', $args);
    $first = TeamMetricSnapshot::query()->firstOrFail();
    expect($first->wins)->toBe(9);
    $this->travelTo(Carbon::parse('2026-11-29 12:00:00', 'UTC'));
    $metric->update(['wins' => 10]);
    expect($metric->fresh()->updated_at->gt($first->captured_at))->toBeTrue();
    expect(Artisan::call('nfl:snapshot-team-metrics', $args))->toBe(1);
    expect(TeamMetricSnapshot::query()->count())->toBe(1)
        ->and($first->fresh()->wins)->toBe(9);
});

it('captures a current observation by default and retains genuine zero fields', function () {
    $metric = metricCaptureClockFixture();
    $metric->update(['wins' => 0, 'losses' => 0, 'recent_form_rating' => 0]);
    expect(Artisan::call('nfl:snapshot-team-metrics', ['--season' => 2026]))->toBe(0);
    $stored = TeamMetricSnapshot::query()->firstOrFail();
    expect($stored->captured_at->eq(now()))->toBeTrue()
        ->and($stored->wins)->toBe(0)
        ->and($stored->losses)->toBe(0)
        ->and((float) $stored->recent_form_rating)->toBe(0.0);
});

it('captures only the owning regular season metric when another phase exists', function () {
    $regular = metricCaptureClockFixture();
    TeamMetric::query()->create([
        'team_id' => $regular->team_id, 'season' => 2026, 'season_type' => '3',
        'wins' => 2, 'losses' => 1, 'predictive_rating' => 1900,
        'calculation_date' => '2026-11-27',
    ]);
    expect(TeamMetric::where('team_id', $regular->team_id)->count())->toBe(2);
    Artisan::call('nfl:snapshot-team-metrics', ['--season' => 2026]);
    $stored = TeamMetricSnapshot::query()->firstOrFail();
    expect(TeamMetricSnapshot::query()->count())->toBe(1)
        ->and($stored->wins)->toBe(9)
        ->and((float) $stored->predictive_rating)->toBe(1700.0);
});

it('keeps an exact retry receipt immutable and admits it only after observation', function () {
    $metric = metricCaptureClockFixture();
    $args = ['--season' => 2026, '--date' => '2026-11-28T12:00:00Z'];
    expect(Artisan::call('nfl:snapshot-team-metrics', $args))->toBe(0);
    $first = TeamMetricSnapshot::firstOrFail()->getAttributes();
    $this->travelTo(Carbon::parse('2026-11-29T12:00:00Z'));
    expect(Artisan::call('nfl:snapshot-team-metrics', $args))->toBe(0)
        ->and(TeamMetricSnapshot::firstOrFail()->getAttributes())->toBe($first);
    $method = new ReflectionMethod(TeamFuturesProjectionService::class, 'metricsByTeam');
    $rows = $method->invoke(app(TeamFuturesProjectionService::class), 2026, now(), true, true);
    expect($rows[$metric->team_id]['_metric_evidence']['mode'])->toBe('current_capture')
        ->and($rows[$metric->team_id]['_metric_evidence']['observed_at'])->toBe('2026-11-28T12:00:00.000000Z');
});

it('rolls back a cancelled snapshot save without acknowledging success', function () {
    metricCaptureClockFixture();
    $dispatcher = TeamMetricSnapshot::getEventDispatcher();
    TeamMetricSnapshot::setEventDispatcher(clone $dispatcher);
    $reached = false;
    TeamMetricSnapshot::creating(function () use (&$reached) {
        $reached = true;

        return false;
    });
    try {
        expect(Artisan::call('nfl:snapshot-team-metrics', ['--season' => 2026]))->toBe(1)
            ->and($reached)->toBeTrue()
            ->and(TeamMetricSnapshot::count())->toBe(0);
    } finally {
        TeamMetricSnapshot::setEventDispatcher($dispatcher);
    }
});

it('labels a current fallback as mutable unverified even without an as of date', function () {
    $metric = metricCaptureClockFixture();
    $method = new ReflectionMethod(TeamFuturesProjectionService::class, 'metricsByTeam');
    $rows = $method->invoke(app(TeamFuturesProjectionService::class), 2026, null, false, true);
    expect($rows[$metric->team_id]['_metric_evidence']['mode'])->toBe('current_fallback_mutable_unverified')
        ->and($rows[$metric->team_id]['_metric_evidence']['historical_availability_proven'])->toBeFalse();
});

it('leaves a legacy row unchanged and excludes it only from strict receipt admission', function () {
    $metric = metricCaptureClockFixture();
    $legacy = TeamMetricSnapshot::create([
        'snapshot_key' => sha1('untouched-legacy'), 'team_id' => $metric->team_id,
        'season' => 2026, 'wins' => 1, 'losses' => 0,
        'calculation_date' => '2026-10-01', 'captured_at' => '2026-10-02T12:00:00Z',
    ]);
    $before = $legacy->fresh()->getAttributes();
    $method = new ReflectionMethod(TeamFuturesProjectionService::class, 'metricsByTeam');
    $reader = app(TeamFuturesProjectionService::class);
    expect($method->invoke($reader, 2026, now(), true, true))->toBe([]);
    $descriptive = $method->invoke($reader, 2026, now(), true, false);
    expect($descriptive[$metric->team_id]['_metric_evidence']['mode'])->toBe('legacy_unverified')
        ->and($legacy->fresh()->getAttributes())->toBe($before);
});

it('refuses altered receipt values and an oversized descriptor without inventing lineage', function () {
    $metric = metricCaptureClockFixture();
    Artisan::call('nfl:snapshot-team-metrics', ['--season' => 2026]);
    $stored = TeamMetricSnapshot::firstOrFail();
    $stored->update(['wins' => 8]);
    $method = new ReflectionMethod(TeamFuturesProjectionService::class, 'metricsByTeam');
    expect($method->invoke(app(TeamFuturesProjectionService::class), 2026, now(), true, true))->toBe([]);
    $stored->update(['wins' => 9, 'observation' => ['padding' => str_repeat('x', 5000)]]);
    expect($method->invoke(app(TeamFuturesProjectionService::class), 2026, now(), true, true))->toBe([]);
});

it('labels reconstruction separately from a current receipt at the same cutoff', function () {
    $metric = metricCaptureClockFixture();
    $cutoff = now()->copy();
    app(TeamMetricSnapshotService::class)->capture(2026, $cutoff);
    $calculator = Mockery::mock(HistoricalTeamMetricCalculator::class);
    $calculator->shouldReceive('calculateForDate')->once()->withArgs(function ($season, $date, $pointInTime, $scorePriors) use ($cutoff) {
        return $season === 2026 && $date->eq($cutoff) && $pointInTime === true && $scorePriors === false;
    })->andReturn([$metric->team_id => [
        'wins' => 0, 'losses' => 0, 'predictive_rating' => 1500,
        'future_strength_of_schedule' => 1500, 'recent_form_rating' => 0,
        'injury_total_adjustment' => 0, 'calculation_date' => '2026-10-01',
    ]]);
    $service = new TeamMetricSnapshotService($calculator);
    expect($service->backfill(2026, [$cutoff->toIso8601String()]))->toBe(1)
        ->and(TeamMetricSnapshot::count())->toBe(2);
    $reconstructed = TeamMetricSnapshot::latest('id')->firstOrFail();
    $lineage = TeamMetricSnapshotObservation::lineage($reconstructed);
    expect($lineage['mode'])->toBe('historical_reconstruction')
        ->and($lineage['historical_availability_proven'])->toBeFalse();
    $method = new ReflectionMethod(TeamFuturesProjectionService::class, 'metricsByTeam');
    $rows = $method->invoke(app(TeamFuturesProjectionService::class), 2026, $cutoff, true, true);
    expect($rows[$metric->team_id]['_metric_evidence']['mode'])->toBe('historical_reconstruction')
        ->and($rows[$metric->team_id]['wins'])->toBe(0);
});

it('does not move observation time to a requested future cutoff', function () {
    $metric = metricCaptureClockFixture();
    $observed = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
    $future = now()->addDay();
    app(TeamMetricSnapshotService::class)->capture(2026, $future);
    $stored = TeamMetricSnapshot::firstOrFail();
    expect($stored->observation['observed_at'])->toBe($observed)
        ->and($stored->captured_at->eq($future))->toBeTrue();
    $method = new ReflectionMethod(TeamFuturesProjectionService::class, 'metricsByTeam');
    expect($method->invoke(app(TeamFuturesProjectionService::class), 2026, now(), true, true))->toBe([]);
    $this->travelTo($future);
    $rows = $method->invoke(app(TeamFuturesProjectionService::class), 2026, now(), true, true);
    expect($rows[$metric->team_id]['_metric_evidence']['mode'])->toBe('current_capture');
});

it('checks every receipt again after the final creating observer', function () {
    metricCaptureClockFixture();
    metricCaptureClockFixture();
    $dispatcher = TeamMetricSnapshot::getEventDispatcher();
    TeamMetricSnapshot::setEventDispatcher(clone $dispatcher);
    $writes = 0;
    TeamMetricSnapshot::created(function () use (&$writes) {
        $writes++;
        if ($writes === 2) {
            TeamMetricSnapshot::query()->oldest('id')->firstOrFail()->delete();
        }
    });
    try {
        expect(Artisan::call('nfl:snapshot-team-metrics', ['--season' => 2026]))->toBe(1)
            ->and($writes)->toBe(2)
            ->and(TeamMetricSnapshot::count())->toBe(0);
    } finally {
        TeamMetricSnapshot::setEventDispatcher($dispatcher);
    }
});

it('keeps an empty season empty without manufacturing a receipt', function () {
    expect(Artisan::call('nfl:snapshot-team-metrics', ['--season' => 2026]))->toBe(0)
        ->and(TeamMetricSnapshot::count())->toBe(0);
    $method = new ReflectionMethod(TeamFuturesProjectionService::class, 'metricsByTeam');
    expect($method->invoke(app(TeamFuturesProjectionService::class), 2026, now(), true, true))->toBe([]);
});

it('refuses receipt metadata drift rather than relabeling its source', function (string $mutation) {
    $metric = metricCaptureClockFixture();
    app(TeamMetricSnapshotService::class)->capture(2026);
    $stored = TeamMetricSnapshot::firstOrFail();
    $receipt = $stored->observation;
    if ($mutation === 'relative-clock') {
        $receipt['observed_at'] = 'yesterday+00:00';
    } elseif ($mutation === 'source-identity') {
        $receipt['source']['metric_id'] = 999999;
    } elseif ($mutation === 'extra-source-field') {
        $receipt['source']['unreviewed_extra'] = 'must not escape';
    } elseif ($mutation === 'cutoff-identity') {
        $receipt['cutoff_at'] = '2026-11-27T12:00:00+00:00';
        $stored->captured_at = '2026-11-27 12:00:00';
    } elseif ($mutation === 'mode-identity') {
        $receipt['mode'] = 'historical_reconstruction';
        $receipt['source'] = ['calculator' => HistoricalTeamMetricCalculator::class, 'reconstruct_score_priors' => false];
    }
    // Extra-field and identity controls also exercise shape/key binding beyond the checksum.
    if (in_array($mutation, ['extra-source-field', 'cutoff-identity', 'mode-identity'], true) && isset($receipt['receipt_hash'])) {
        unset($receipt['receipt_hash']);
        $receipt['receipt_hash'] = TeamMetricSnapshotObservation::descriptorHash($receipt);
    }
    $stored->observation = $receipt;
    $stored->save();
    $method = new ReflectionMethod(TeamFuturesProjectionService::class, 'metricsByTeam');
    expect($method->invoke(app(TeamFuturesProjectionService::class), 2026, now(), true, true))->toBe([]);
})->with(['relative-clock', 'source-identity', 'extra-source-field', 'cutoff-identity', 'mode-identity']);

it('round trips the declared application wall carrier including real local midnight', function (string $instant, string $wall) {
    config(['app.timezone' => 'America/Chicago']);
    date_default_timezone_set('America/Chicago');
    $this->travelTo(Carbon::parse($instant));
    $metric = metricCaptureClockFixture();
    expect(Artisan::call('nfl:snapshot-team-metrics', ['--season' => 2026]))->toBe(0);
    $stored = TeamMetricSnapshot::firstOrFail();
    expect($stored->getRawOriginal('captured_at'))->toBe($wall)
        ->and($stored->observation['cutoff_at'])->toBe(now()->utc()->toIso8601String())
        ->and($stored->observation['storage_timezone'])->toBe('America/Chicago');
    $method = new ReflectionMethod(TeamFuturesProjectionService::class, 'metricsByTeam');
    $rows = $method->invoke(app(TeamFuturesProjectionService::class), 2026, now(), true, true);
    expect($rows[$metric->team_id]['_metric_evidence']['mode'])->toBe('current_capture');
})->with([
    ['2026-11-28T12:00:00Z', '2026-11-28 06:00:00'],
    ['2026-11-28T06:00:00Z', '2026-11-28 00:00:00'],
]);

it('does not admit an observation from later in the same stored second', function () {
    $this->travelTo(Carbon::parse('2026-11-28T12:00:00.500000Z'));
    $metric = metricCaptureClockFixture();
    expect(Artisan::call('nfl:snapshot-team-metrics', ['--season' => 2026]))->toBe(0);
    $method = new ReflectionMethod(TeamFuturesProjectionService::class, 'metricsByTeam');
    $rows = $method->invoke(app(TeamFuturesProjectionService::class), 2026, Carbon::parse('2026-11-28T12:00:00.000000Z'), true, true);
    expect($rows)->not->toHaveKey($metric->team_id);
});

it('retains actual observation microseconds and admits only at or after that instant', function () {
    $this->travelTo(Carbon::parse('2026-11-28T12:00:00.500000Z'));
    $metric = metricCaptureClockFixture();
    expect(Artisan::call('nfl:snapshot-team-metrics', ['--season' => 2026]))->toBe(0);
    $stored = TeamMetricSnapshot::firstOrFail();
    expect($stored->getRawOriginal('captured_at'))->toBe('2026-11-28 12:00:00')
        ->and($stored->observation['observed_at'])->toBe('2026-11-28T12:00:00.500000Z');
    $method = new ReflectionMethod(TeamFuturesProjectionService::class, 'metricsByTeam');
    $rows = $method->invoke(app(TeamFuturesProjectionService::class), 2026, now(), true, true);
    expect($rows[$metric->team_id]['_metric_evidence']['observed_at'])->toBe('2026-11-28T12:00:00.500000Z');
});

it('refuses an explicitly fractional cutoff rather than silently truncating it', function () {
    expect(Artisan::call('nfl:snapshot-team-metrics', [
        '--season' => 2026, '--date' => '2026-11-28T12:00:00.500000Z',
    ]))->toBe(1)->and(TeamMetricSnapshot::count())->toBe(0);
    metricCaptureClockFixture();
    expect(Artisan::call('nfl:snapshot-team-metrics', [
        '--season' => 2026, '--date' => '2026-11-28T12:00:00.500000Z',
    ]))->toBe(1)->and(TeamMetricSnapshot::count())->toBe(0);
});
