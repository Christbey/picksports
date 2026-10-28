<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\NFL\NflModelMonitoringService;
use App\Services\NFL\NflMonitorReportCache;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
});
afterEach(function () {
    Carbon::setTestNow();
});

it('returns a cold admin page without executing report generation or sports queries', function () {
    $admin = User::factory()->admin()->create();
    $service = Mockery::mock(NflModelMonitoringService::class)->makePartial();
    $service->shouldReceive('dashboard')->never();
    $service->shouldReceive('signalGradePayload')->never();
    app()->instance(NflModelMonitoringService::class, $service);
    $sportsQueries = [];
    DB::listen(function ($event) use (&$sportsQueries): void {
        if (preg_match('/nfl_signal_|prediction_feature_snapshots|shadow_model_outputs|bet_decisions/', $event->sql)) {
            $sportsQueries[] = $event->sql;
        }
    });
    $this->actingAs($admin)->get(route('admin.nfl-model-monitoring'))
        ->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/NflModelMonitoring')
        ->where('report_status.state', 'pending')->where('evidence_leaderboard', null)->has('signal_grades', 0));
    expect($sportsQueries)->toBeEmpty();
});

it('serves last-good reports beyond the stale threshold without rebuilding in HTTP', function () {
    $admin = User::factory()->admin()->create();
    $empty = app(NflModelMonitoringService::class)->emptyDashboard();
    $service = Mockery::mock(NflModelMonitoringService::class)->makePartial();
    $service->shouldReceive('dashboard')->once()->with(null, [], false)->andReturn($empty);
    $service->shouldReceive('signalGradePayload')->once()->andReturn([['signal_type' => 'reason_code', 'signals' => [], 'windows' => []]]);
    app()->instance(NflModelMonitoringService::class, $service);
    $reports = app(NflMonitorReportCache::class);
    expect($reports->refresh()['built'])->toBe(1);
    Carbon::setTestNow(now()->addHours(2));
    $this->actingAs($admin)->get(route('admin.nfl-model-monitoring', ['signals' => 1]))
        ->assertOk()->assertInertia(fn (Assert $p) => $p->where('report_status.state', 'stale')->has('signal_grades', 1));
});

it('preserves both prior dashboard and legacy grades when the builder fails', function () {
    $empty = app(NflModelMonitoringService::class)->emptyDashboard();
    $service = Mockery::mock(NflModelMonitoringService::class)->makePartial();
    $service->shouldReceive('dashboard')->once()->andReturn($empty)->ordered();
    $service->shouldReceive('signalGradePayload')->once()->andReturn([['saved' => true]])->ordered();
    $service->shouldReceive('dashboard')->once()->andThrow(new RuntimeException('Fixture failure'))->ordered();
    $service->shouldReceive('signalGradePayload')->once()->andThrow(new RuntimeException('Fixture failure'))->ordered();
    app()->instance(NflModelMonitoringService::class, $service);
    $reports = app(NflMonitorReportCache::class);
    $reports->refresh();
    $before = $reports->read(null, []);
    expect($reports->refresh()['failures'])->toBe(['dashboard', 'signal_grades']);
    expect($reports->read(null, [])['payload'])->toBe($before['payload'])->and($reports->legacy()['grades'])->toBe([['saved' => true]]);
});

it('separates requested scopes and bounds background work', function () {
    $empty = app(NflModelMonitoringService::class)->emptyDashboard();
    $service = Mockery::mock(NflModelMonitoringService::class)->makePartial();
    $service->shouldReceive('dashboard')->times(3)->andReturn($empty);
    $service->shouldReceive('signalGradePayload')->once()->andReturn([]);
    app()->instance(NflModelMonitoringService::class, $service);
    $reports = app(NflMonitorReportCache::class);
    foreach (range(2000, 2025) as $season) {
        $reports->read(null, ['from_season' => $season, 'to_season' => $season]);
    }
    expect($reports->refresh()['built'])->toBe(3);
    expect($reports->read(null, ['from_season' => 2025, 'to_season' => 2025])['payload'])->toBe($empty)
        ->and($reports->read(null, ['from_season' => 2000])['payload'])->toBeNull();
});

it('never builds concurrently and rejects invalid command limits', function () {
    $lock = Cache::lock('nfl:monitor:reports:v1:build-lock', 900);
    $lock->get();
    try {
        expect(app(NflMonitorReportCache::class)->refresh()['status'])->toBe('already_running');
    } finally {
        $lock->release();
    }
    $this->artisan('nfl:refresh-model-monitor', ['--limit' => 0])->assertFailed();
});

it('only returns requested partial props from saved reports', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->withHeaders(['X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'Admin/NflModelMonitoring', 'X-Inertia-Partial-Data' => 'signal_grades'])
        ->get(route('admin.nfl-model-monitoring'))->assertOk()->assertJsonCount(0, 'props.signal_grades')
        ->assertJsonMissingPath('props.evidence_leaderboard')->assertJsonMissingPath('props.observations');
});

it('checks pending report scopes outside HTTP every minute', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'nfl:refresh-model-monitor'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *')->and($event->timezone)->toBe('America/Chicago')
        ->and($event->onOneServer)->toBeTrue()->and($event->withoutOverlapping)->toBeTrue();
});

it('skips fresh background reports and builds newly requested scopes without waiting for expiry', function () {
    $empty = app(NflModelMonitoringService::class)->emptyDashboard();
    $service = Mockery::mock(NflModelMonitoringService::class)->makePartial();
    $service->shouldReceive('dashboard')->twice()->andReturn($empty);
    $service->shouldReceive('signalGradePayload')->once()->andReturn([]);
    app()->instance(NflModelMonitoringService::class, $service);
    $reports = app(NflMonitorReportCache::class);
    expect($reports->refresh(3, false)['built'])->toBe(1)->and($reports->refresh(3, false)['built'])->toBe(0);
    $reports->read(null, ['from_season' => 2024]);
    expect($reports->refresh(3, false)['built'])->toBe(1);
});
