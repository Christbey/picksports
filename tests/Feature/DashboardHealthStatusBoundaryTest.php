<?php

use App\Models\Healthcheck;
use App\Models\User;
use App\Services\Api\V2\DashboardSportConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-27T12:00:00-06:00'));
    Cache::flush();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    // An authorized empty slate isolates health reporting, not its status calculation.
    $this->mock(DashboardSportConfig::class)->shouldReceive('viewable')->andReturn([]);
});

afterEach(function () {
    $this->travelBack();
});

it('reports only actually observed recent dashboard health without inferring success from absence', function (array $checks, string $expected) {
    foreach ($checks as [$status, $minutesAgo]) {
        Healthcheck::create(['sport' => 'nfl', 'check_type' => 'heartbeat_test', 'status' => $status,
            'message' => 'Synthetic dashboard status fixture', 'checked_at' => now()->subMinutes($minutesAgo)]);
    }
    $response = $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();
    expect($response->inertiaProps('stats.healthcheck_status'))->toBe($expected)
        ->and($response->inertiaProps('stats.total_predictions_today'))->toBe(0);
    Http::assertNothingSent();
})->with([
    'no rows' => [[], 'unknown'],
    'future passing' => [[['passing', -1]], 'unknown'],
    'future failing' => [[['failing', -1]], 'unknown'],
    'exact hour passing' => [[['passing', 60]], 'passing'],
    'stale passing' => [[['passing', 61]], 'unknown'],
    'fresh passing' => [[['passing', 10]], 'passing'],
    'fresh failing' => [[['failing', 10]], 'failing'],
    'fresh unknown' => [[['unknown', 10]], 'unknown'],
    'mixed passing unknown' => [[['passing', 5], ['unknown', 10]], 'unknown'],
    'sticky failure despite newer pass' => [[['failing', 50], ['passing', 5]], 'failing'],
    'warning dominates passing' => [[['warning', 10], ['passing', 5]], 'warning'],
    'failing dominates unknown' => [[['failing', 10], ['unknown', 5]], 'failing'],
    'fresh warning' => [[['warning', 10]], 'warning'],
]);
