<?php

namespace App\Actions\ESPN\MLB\Concerns;

use App\DataTransferObjects\ESPN\GameData;
use Carbon\CarbonImmutable;

trait ResolvesMlbGameDateParts
{
    /**
     * @param  array<string, mixed>  $rawGame
     * @return array{game_date:?string,game_time:?string}
     */
    protected function resolveMlbGameDateParts(array $rawGame): array
    {
        $dateTime = is_scalar($rawGame['date'] ?? null) ? (string) $rawGame['date'] : null;
        if ($dateTime === null || $dateTime === '') {
            return GameData::extractDateParts(null);
        }

        try {
            $scheduledAt = CarbonImmutable::parse($dateTime);
        } catch (\Throwable) {
            return GameData::extractDateParts($dateTime);
        }

        $competition = $rawGame['competitions'][0] ?? data_get($rawGame, 'header.competitions.0', []);
        $venueCity = data_get($competition, 'venue.address.city');
        $venueState = data_get($competition, 'venue.address.state');
        $timezone = $this->mlbVenueTimezone(
            is_scalar($venueCity) ? (string) $venueCity : null,
            is_scalar($venueState) ? (string) $venueState : null,
        );

        $local = $scheduledAt->setTimezone($timezone);

        return [
            'game_date' => $local->toDateString(),
            'game_time' => $local->format('H:i:s'),
        ];
    }

    protected function mlbVenueTimezone(?string $venueCity, ?string $venueState): string
    {
        $city = strtolower(trim((string) $venueCity));
        $state = strtoupper(trim((string) $venueState));

        $cityStateOverrides = [
            'phoenix|AZ' => 'America/Phoenix',
            'scottsdale|AZ' => 'America/Phoenix',
            'los angeles|CA' => 'America/Los_Angeles',
            'anaheim|CA' => 'America/Los_Angeles',
            'san diego|CA' => 'America/Los_Angeles',
            'san francisco|CA' => 'America/Los_Angeles',
            'seattle|WA' => 'America/Los_Angeles',
            'denver|CO' => 'America/Denver',
            'arlington|TX' => 'America/Chicago',
            'houston|TX' => 'America/Chicago',
            'minneapolis|MN' => 'America/Chicago',
            'st. louis|MO' => 'America/Chicago',
            'st louis|MO' => 'America/Chicago',
            'chicago|IL' => 'America/Chicago',
            'milwaukee|WI' => 'America/Chicago',
            'cincinnati|OH' => 'America/New_York',
            'cleveland|OH' => 'America/New_York',
            'detroit|MI' => 'America/New_York',
            'atlanta|GA' => 'America/New_York',
            'miami|FL' => 'America/New_York',
            'tampa|FL' => 'America/New_York',
            'st. petersburg|FL' => 'America/New_York',
            'st petersburg|FL' => 'America/New_York',
            'baltimore|MD' => 'America/New_York',
            'boston|MA' => 'America/New_York',
            'bronx|NY' => 'America/New_York',
            'queens|NY' => 'America/New_York',
            'pittsburgh|PA' => 'America/New_York',
            'philadelphia|PA' => 'America/New_York',
            'washington|DC' => 'America/New_York',
            'toronto|ON' => 'America/Toronto',
            'toronto|ONTARIO' => 'America/Toronto',
        ];

        $overrideKey = sprintf('%s|%s', $city, $state);

        if (isset($cityStateOverrides[$overrideKey])) {
            return $cityStateOverrides[$overrideKey];
        }

        $stateTimezones = [
            'AZ' => 'America/Phoenix',
            'ARIZONA' => 'America/Phoenix',
            'CA' => 'America/Los_Angeles',
            'CALIFORNIA' => 'America/Los_Angeles',
            'WA' => 'America/Los_Angeles',
            'WASHINGTON' => 'America/Los_Angeles',
            'CO' => 'America/Denver',
            'COLORADO' => 'America/Denver',
            'TX' => 'America/Chicago',
            'TEXAS' => 'America/Chicago',
            'MN' => 'America/Chicago',
            'MINNESOTA' => 'America/Chicago',
            'MO' => 'America/Chicago',
            'MISSOURI' => 'America/Chicago',
            'IL' => 'America/Chicago',
            'ILLINOIS' => 'America/Chicago',
            'WI' => 'America/Chicago',
            'WISCONSIN' => 'America/Chicago',
            'OH' => 'America/New_York',
            'OHIO' => 'America/New_York',
            'MI' => 'America/New_York',
            'MICHIGAN' => 'America/New_York',
            'GA' => 'America/New_York',
            'GEORGIA' => 'America/New_York',
            'FL' => 'America/New_York',
            'FLORIDA' => 'America/New_York',
            'MD' => 'America/New_York',
            'MARYLAND' => 'America/New_York',
            'MA' => 'America/New_York',
            'MASSACHUSETTS' => 'America/New_York',
            'NY' => 'America/New_York',
            'NEW YORK' => 'America/New_York',
            'PA' => 'America/New_York',
            'PENNSYLVANIA' => 'America/New_York',
            'DC' => 'America/New_York',
            'DISTRICT OF COLUMBIA' => 'America/New_York',
            'ON' => 'America/Toronto',
            'ONTARIO' => 'America/Toronto',
        ];

        return $stateTimezones[$state] ?? 'UTC';
    }
}
