<?php

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CalculationRelease;
use App\Models\SportEvent;
use App\Services\Predictions\CalculationReleaseManager;
use App\Services\Predictions\CalculationReleaseSelector;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['app.timezone' => 'America/Chicago']);
    $this->travelTo(CarbonImmutable::parse('2026-11-25T18:00:00-06:00'));
});
afterEach(fn () => $this->travelBack());

it('stores approval and retirement instants in the application timezone regardless of supplied offset', function (string $timezone) {
    $event = SportEvent::factory()->create(['sport' => 'nfl']);
    $start = CarbonImmutable::parse('2026-11-25 18:00:00', 'America/Chicago');
    $end = $start->addHour();
    $manager = app(CalculationReleaseManager::class);
    $release = $manager->approve(
        CalculationRelease::factory()->create(['sport' => 'nfl', 'phase' => 'pregame']),
        'timezone-regression', 'Equivalent instant test', $start->setTimezone($timezone),
    );
    expect($release->getRawOriginal('effective_at'))->toBe('2026-11-25 18:00:00')
        ->and($release->effective_at->equalTo($start))->toBeTrue();
    $selector = app(CalculationReleaseSelector::class);
    foreach (['UTC', 'Asia/Tokyo', 'America/Chicago'] as $queryTimezone) {
        expect($selector->select($event, 'pregame', $start->setTimezone($queryTimezone))->id)->toBe($release->id);
        expect(fn () => $selector->select($event, 'pregame', $start->subSecond()->setTimezone($queryTimezone)))
            ->toThrow(PredictionLifecycleException::class, 'found 0');
    }
    $retired = $manager->retire($release, $end->setTimezone($timezone));
    expect($retired->getRawOriginal('retired_at'))->toBe('2026-11-25 19:00:00')
        ->and($retired->retired_at->equalTo($end))->toBeTrue();
    foreach (['UTC', 'Asia/Tokyo', 'America/Chicago'] as $queryTimezone) {
        expect($selector->select($event, 'pregame', $end->subSecond()->setTimezone($queryTimezone))->id)->toBe($release->id);
        expect(fn () => $selector->select($event, 'pregame', $end->setTimezone($queryTimezone)))
            ->toThrow(PredictionLifecycleException::class, 'found 0');
    }
})->with(['UTC', 'Asia/Tokyo', 'America/Chicago']);

it('preserves naive application-local and default-now activation behavior', function () {
    $event = SportEvent::factory()->create(['sport' => 'nfl']);
    $manager = app(CalculationReleaseManager::class);
    $release = $manager->approve(
        CalculationRelease::factory()->create(['sport' => 'nfl', 'phase' => 'pregame']),
        'timezone-regression', 'Naive local compatibility', CarbonImmutable::parse('2026-11-25 18:00:00', config('app.timezone')),
    );
    expect(app(CalculationReleaseSelector::class)->select($event, 'pregame')->id)->toBe($release->id);
    $this->travel(1)->hours();
    $manager->retire($release);
    expect($release->fresh()->getRawOriginal('retired_at'))->toBe('2026-11-25 19:00:00');
    $replacement = $manager->approve(
        CalculationRelease::factory()->create(['sport' => 'nfl', 'phase' => 'pregame']),
        'timezone-regression', 'Default now compatibility',
    );
    expect($replacement->getRawOriginal('effective_at'))->toBe('2026-11-25 19:00:00')
        ->and(app(CalculationReleaseSelector::class)->select($event, 'pregame')->id)->toBe($replacement->id);
});
