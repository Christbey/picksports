<?php

use App\Services\NBA\Predictions\NbaInputSnapshotBuilder;
use App\Services\Predictions\CanonicalTeamInputSnapshotBuilder;
use Carbon\CarbonImmutable;

it('selects the latest source by instant across stored offset and UTC evidence', function (array $sources, ?string $expected) {
    $method = new ReflectionMethod(CanonicalTeamInputSnapshotBuilder::class, 'latestTimestamp');
    $actual = $method->invoke(new NbaInputSnapshotBuilder, $sources);
    if ($expected === null) {
        expect($actual)->toBeNull();
    } else {
        expect($actual)->toBeInstanceOf(CarbonImmutable::class)
            ->and($actual->getTimestamp())->toBe(CarbonImmutable::parse($expected)->getTimestamp());
    }
})->with([
    'application timestamp later than lexical UTC winner' => [[
        'game' => '2026-11-27T01:00:00-05:00',
        'mlb_scheduled_start_observed_at_utc' => '2026-11-27T05:30:00.000000Z',
    ], '2026-11-27T06:00:00Z'],
    'positive offset later civil date is earlier instant' => [[
        'metric' => '2026-11-28T00:15:00+02:00',
        'game' => '2026-11-27T23:00:00Z',
    ], '2026-11-27T23:00:00Z'],
    'ordinary equal-offset ordering' => [[
        'game' => '2026-11-27T01:00:00-05:00',
        'metric' => '2026-11-27T02:00:00-05:00',
    ], '2026-11-27T07:00:00Z'],
    'all unknown' => [['game' => null], null],
]);
