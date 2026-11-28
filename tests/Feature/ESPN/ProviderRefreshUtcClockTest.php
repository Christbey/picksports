<?php

use App\Models\NFL\Team;
use App\Services\ProviderData\Refresh\ProviderRefreshCoordinator;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../../Support/ProviderRefreshFixture.php';

it('preserves both Chicago fold instants as explicit UTC database bytes and round trips', function (string $instant, string $utc) {
    config(['app.timezone' => 'America/Chicago']);
    $this->travelTo(CarbonImmutable::parse($instant));
    try {
        $team = Team::factory()->create(['espn_id' => '22']);
        enableSyntheticRefreshScope($team, 'injuries', 0);
        $context = app(ProviderRefreshCoordinator::class)->begin($team, 'injuries', 0);
        $raw = (array) $team->getConnection()->table('provider_refresh_attempts')->find($context->attempt->id);
        expect(substr($raw['started_at'], 0, 19))->toBe($utc)
            ->and(substr($raw['created_at'], 0, 19))->toBe($utc)
            ->and($context->attempt->fresh()->started_at->utc()->format('Y-m-d H:i:s'))->toBe($utc);
    } finally {
        $this->travelBack();
    }
})->with([
    ['2026-11-01T01:30:00-05:00', '2026-11-01 06:30:00'],
    ['2026-11-01T01:30:00-06:00', '2026-11-01 07:30:00'],
]);

it('keeps a positive cross-fold lease valid and expires against the actual UTC instant', function () {
    config(['app.timezone' => 'America/Chicago']);
    $this->travelTo(CarbonImmutable::parse('2026-11-01T01:59:30-05:00'));
    try {
        $team = Team::factory()->create(['espn_id' => '22']);
        enableSyntheticRefreshScope($team, 'injuries', 0);
        $context = app(ProviderRefreshCoordinator::class)->begin($team, 'injuries', 0);
        expect($context->attempt->fresh()->started_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-01 06:59:30')
            ->and($context->attempt->fresh()->expires_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-01 07:01:30');
        $this->travelTo(CarbonImmutable::parse('2026-11-01T01:01:00-06:00'));
        expect($context->commit(true, [], fn () => 0))->toBe(0)
            ->and($context->attempt->fresh()->outcome)->toBe('applied');
    } finally {
        $this->travelBack();
    }
});

it('uses one real UTC clock observation for the maximum allowed lease', function () {
    $this->travelBack();
    config(['provider_refresh.lease_seconds' => 300]);
    $team = Team::factory()->create(['espn_id' => '22']);
    enableSyntheticRefreshScope($team, 'injuries', 0);
    $before = CarbonImmutable::now('UTC');
    $context = app(ProviderRefreshCoordinator::class)->begin($team, 'injuries', 0);
    $after = CarbonImmutable::now('UTC');
    $attempt = $context->attempt->fresh();
    expect($attempt->expires_at->diffInMicroseconds($attempt->started_at, true))->toBe(300000000.0)
        ->and($attempt->started_at->betweenIncluded($before, $after))->toBeTrue();
});
