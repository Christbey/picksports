<?php

use App\Services\AlertService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

function fakeAlertScopeService(array &$calls, int $specificCount = 0): void
{
    $service = Mockery::mock(AlertService::class);
    $service->shouldReceive('checkForValueOpportunities')->andReturnUsing(function (string $sport) use (&$calls, $specificCount): int {
        $calls[] = 'specific:'.$sport;

        return $specificCount;
    });
    $service->shouldReceive('checkAllSports')->andReturnUsing(function () use (&$calls): array {
        $calls[] = 'all';

        return array_fill_keys(['nfl', 'nba', 'cbb', 'wcbb', 'mlb', 'cfb', 'wnba'], 0);
    });
    app()->instance(AlertService::class, $service);
    Notification::fake();
    Http::preventStrayRequests();
}

it('refuses explicitly invalid alert selectors before any service call', function (string $sport) {
    $calls = [];
    fakeAlertScopeService($calls);
    $exit = Artisan::call('alerts:check', ['sport' => $sport]);
    expect($calls)->toBe([])->and($exit)->toBe(1);
    Notification::assertNothingSent();
    Http::assertNothingSent();
})->with(['0', '', ' ', 'nhl', 'nfljunk']);

it('preserves the omitted selector as the legitimate all sport operation', function () {
    $calls = [];
    fakeAlertScopeService($calls);
    expect(Artisan::call('alerts:check'))->toBe(0)->and($calls)->toBe(['all']);
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

it('preserves the explicit normalized nfl selector without all sport widening', function () {
    $calls = [];
    fakeAlertScopeService($calls);
    expect(Artisan::call('alerts:check', ['sport' => 'NFL']))->toBe(0)->and($calls)->toBe(['specific:nfl']);
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

it('labels the returned alert count as dispatch rather than transport delivery', function () {
    $calls = [];
    fakeAlertScopeService($calls, 2);
    expect(Artisan::call('alerts:check', ['sport' => 'nfl']))->toBe(0);
    expect(Artisan::output())->toContain('Dispatched 2 alert(s) for nfl')->not->toContain('Sent 2');
    expect($calls)->toBe(['specific:nfl']);
    Notification::assertNothingSent();
    Http::assertNothingSent();
});
