<?php

namespace App\Services\NFL\Matchups;

final class NflMatchupAltitudeContext
{
    public const HIGH_METERS = 1500;

    public const LOW_METERS = 150;

    public function at(string $stadiumId): ?array
    {
        $evidence = config('nfl_stadium_elevations.stadiums.'.$stadiumId);
        $elevation = $evidence['elevation_meters'] ?? null;
        $coordinates = config('nfl_stadium_geography.stadiums.'.$stadiumId);
        $point = $evidence['response']['location'] ?? null;
        if (! is_array($coordinates) || ! is_array($point)
            || ! is_numeric($point['x'] ?? null) || ! is_numeric($point['y'] ?? null)
            || ! is_numeric($coordinates['latitude'] ?? null) || ! is_numeric($coordinates['longitude'] ?? null)
            || abs((float) $point['x'] - (float) $coordinates['longitude']) > 0.00000001
            || abs((float) $point['y'] - (float) $coordinates['latitude']) > 0.00000001
            || config('nfl_stadium_elevations.coordinate_source_sha256') !== config('nfl_stadium_geography.source_sha256')
            || ! is_array($evidence) || ! is_numeric($elevation) || ! is_finite((float) $elevation)
            || $elevation < -500 || $elevation > 9000
            || empty($evidence['source_url']) || empty($evidence['source_sha256'])) {
            return null;
        }

        return ['stadium_id' => $stadiumId, 'elevation_meters' => (float) $elevation,
            'source' => 'usgs_3dep_elevation_point_query', 'source_url' => $evidence['source_url'],
            'source_sha256' => $evidence['source_sha256'],
            'observed_at' => config('nfl_stadium_elevations.observed_at'),
            'coordinate_source_sha256' => config('nfl_stadium_elevations.coordinate_source_sha256'),
            'resolution_meters' => $evidence['response']['resolution'] ?? null,
            'acquisition_date' => $evidence['response']['attributes']['AcquisitionDate'] ?? null];
    }
}
