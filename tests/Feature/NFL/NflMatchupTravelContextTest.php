<?php

use App\Services\NFL\Matchups\NflMatchupTravelContext;
use Carbon\CarbonImmutable;

it('calculates a plausible coast-to-coast great-circle distance with source provenance', function () {
    $service = app(NflMatchupTravelContext::class);
    $kickoff = CarbonImmutable::parse('2026-09-20T17:00:00Z');
    $forward = $service->between('LAX01', 'NYC01', $kickoff);
    $reverse = $service->between('NYC01', 'LAX01', $kickoff);
    expect($forward['distance_miles'])->toBeGreaterThan(2400)->toBeLessThan(2500)
        ->and(abs($forward['distance_miles'] - $reverse['distance_miles']))->toBeLessThan(0.00001)
        ->and((float) $forward['clock_difference_hours'])->toBe(3.0)
        ->and((float) $reverse['clock_difference_hours'])->toBe(-3.0)
        ->and($forward['destination_local_kickoff'])->toBe('2026-09-20T13:00:00-04:00')
        ->and($forward['mode'])->toBe('home_base_comparison')
        ->and($forward['source_url'])->toContain('15ca8b0f32a881002577aa4aa960915c4cd06847')
        ->and(strlen($forward['source_sha256']))->toBe(64)
        ->and($service->between('LAX01', 'LAX01', $kickoff)['distance_miles'])->toBe(0.0);
});

it('uses kickoff date offsets rather than a fixed zone difference for Arizona', function (string $date, float $difference) {
    $result = app(NflMatchupTravelContext::class)->between('PHO00', 'DEN00', CarbonImmutable::parse($date));
    expect((float) $result['clock_difference_hours'])->toBe($difference);
})->with([['2026-09-20T17:00:00Z', 1.0], ['2026-12-06T18:00:00Z', 0.0]]);

it('holds unknown stadiums and invalid coordinates or timezones', function (array $location) {
    config(['nfl_stadium_geography.stadiums.TEST' => $location]);
    $service = app(NflMatchupTravelContext::class);
    $kickoff = CarbonImmutable::parse('2026-09-20T17:00:00Z');
    expect($service->between('TEST', 'NYC01', $kickoff))->toBeNull()
        ->and($service->between('LAX01', 'UNKNOWN', $kickoff))->toBeNull();
})->with([
    [[]], [['latitude' => null, 'longitude' => 0, 'timezone' => 'America/New_York']],
    [['latitude' => 91, 'longitude' => 0, 'timezone' => 'America/New_York']],
    [['latitude' => 0, 'longitude' => 181, 'timezone' => 'America/New_York']],
    [['latitude' => 0, 'longitude' => 0, 'timezone' => 'Invalid/Zone']],
]);

it('has usable location data for every supported domestic stadium', function () {
    $stadiums = config('nfl_stadium_geography.stadiums');
    expect($stadiums)->toHaveCount(30);
    foreach (array_keys($stadiums) as $id) {
        expect(app(NflMatchupTravelContext::class)->between($id, 'NYC01', CarbonImmutable::parse('2026-09-20T17:00:00Z')))->not->toBeNull();
    }
});
