<?php

use App\Services\NFL\Matchups\NflMatchupAltitudeContext;

it('loads meter-based elevation with source provenance for all domestic stadiums', function () {
    $service = app(NflMatchupAltitudeContext::class);
    $ids = array_keys(config('nfl_stadium_geography.stadiums'));
    expect($ids)->toHaveCount(30);
    foreach ($ids as $id) {
        $evidence = $service->at($id);
        expect($evidence)->not->toBeNull()
            ->and($evidence['source'])->toBe('usgs_3dep_elevation_point_query')
            ->and($evidence['source_url'])->toContain('units=Meters')
            ->and(strlen($evidence['source_sha256']))->toBe(64)
            ->and($evidence['coordinate_source_sha256'])->toBe(config('nfl_stadium_geography.source_sha256'));
        $response = config('nfl_stadium_elevations.stadiums.'.$id.'.response');
        expect((float) $response['location']['x'])->toBe((float) config('nfl_stadium_geography.stadiums.'.$id.'.longitude'))
            ->and((float) $response['location']['y'])->toBe((float) config('nfl_stadium_geography.stadiums.'.$id.'.latitude'))
            ->and((float) $response['value'])->toBe($evidence['elevation_meters']);
    }
    expect($service->at('DEN00')['elevation_meters'])->toBeGreaterThan(1500)->toBeLessThan(1700)
        ->and($service->at('MIA00')['elevation_meters'])->toBeLessThan(10)
        ->and($service->at('UNKNOWN'))->toBeNull();
});

it('rejects absent nonfinite sentinel and implausible elevations', function (mixed $elevation) {
    config(['nfl_stadium_elevations.stadiums.DEN00.elevation_meters' => $elevation]);
    expect(app(NflMatchupAltitudeContext::class)->at('DEN00'))->toBeNull();
})->with([null, 'unknown', INF, NAN, -1000000, 9001]);

it('holds elevation when its source point no longer matches the configured stadium', function (string $fault) {
    if ($fault === 'coordinates') {
        config(['nfl_stadium_geography.stadiums.DEN00.latitude' => 40.0]);
    } else {
        config(['nfl_stadium_geography.source_sha256' => 'changed']);
    }
    expect(app(NflMatchupAltitudeContext::class)->at('DEN00'))->toBeNull();
})->with(['coordinates', 'source revision']);
