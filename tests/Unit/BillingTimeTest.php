<?php

use App\Services\Billing\BillingProviderFailure;
use App\Services\Billing\BillingTime;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

it('roundtrips ordinary provider instants through the actual naive application timezone cast', function (string $timezone, string $instant) {
    config(['app.timezone' => $timezone]);
    $epoch = CarbonImmutable::parse($instant)->getTimestamp();
    $stored = BillingTime::sql($epoch)->format('Y-m-d H:i:s');
    expect(CarbonImmutable::parse($stored, $timezone)->getTimestamp())->toBe($epoch)
        ->and(BillingTime::sql(null))->toBeNull();
})->with([
    ['UTC', '2026-11-01T07:30:00Z'],
    ['America/Chicago', '2026-07-01T17:30:00Z'],
    ['America/Chicago', '2026-01-01T17:30:00Z'],
]);

it('does not persist the repeated-hour provider instant that a naive SQL cast would change', function () {
    config(['app.timezone' => 'America/Chicago']);
    $accepted = [];
    $refused = [];
    foreach (['2026-11-01T06:30:00Z', '2026-11-01T07:30:00Z'] as $instant) {
        $epoch = CarbonImmutable::parse($instant)->getTimestamp();
        try {
            $stored = BillingTime::sql($epoch)->format('Y-m-d H:i:s');
            expect(CarbonImmutable::parse($stored, 'America/Chicago')->getTimestamp())->toBe($epoch);
            $accepted[] = $epoch;
        } catch (BillingProviderFailure $error) {
            expect($error->category)->toBe('provider_timestamp_not_representable');
            $refused[] = $epoch;
        }
    }
    expect($accepted)->toHaveCount(1)->and($refused)->toHaveCount(1);
});
