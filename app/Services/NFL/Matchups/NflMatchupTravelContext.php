<?php

namespace App\Services\NFL\Matchups;

use Carbon\CarbonImmutable;
use DateTimeZone;

/** Home-base comparisons; these do not establish an actual team itinerary. */
final class NflMatchupTravelContext
{
    public function between(string $homeStadium, string $gameStadium, CarbonImmutable $kickoff): ?array
    {
        $home = config('nfl_stadium_geography.stadiums.'.$homeStadium);
        $destination = config('nfl_stadium_geography.stadiums.'.$gameStadium);
        foreach ([$home, $destination] as $location) {
            if (! is_array($location) || ! is_numeric($location['latitude'] ?? null) || ! is_numeric($location['longitude'] ?? null)
                || abs((float) $location['latitude']) > 90 || abs((float) $location['longitude']) > 180
                || ! in_array($location['timezone'] ?? null, DateTimeZone::listIdentifiers(), true)) {
                return null;
            }
        }
        $lat1 = deg2rad((float) $home['latitude']);
        $lat2 = deg2rad((float) $destination['latitude']);
        $deltaLat = $lat2 - $lat1;
        $deltaLon = deg2rad((float) $destination['longitude'] - (float) $home['longitude']);
        $haversine = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;
        $miles = 3958.7613 * 2 * asin(sqrt(max(0.0, min(1.0, $haversine))));
        $homeTime = $kickoff->setTimezone($home['timezone']);
        $localTime = $kickoff->setTimezone($destination['timezone']);

        return ['mode' => 'home_base_comparison', 'distance_miles' => $miles,
            'clock_difference_hours' => ($localTime->getOffset() - $homeTime->getOffset()) / 3600,
            'destination_local_kickoff' => $localTime->toIso8601String(),
            'home_stadium_id' => $homeStadium, 'game_stadium_id' => $gameStadium,
            'home_location' => $home, 'game_location' => $destination,
            'source_url' => config('nfl_stadium_geography.source_url'),
            'source_sha256' => config('nfl_stadium_geography.source_sha256')];
    }
}
