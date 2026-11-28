<?php

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

function periodCapabilityScheduleAt(string $date): Collection
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($date.' 12:00', 'America/Chicago'));
    Carbon::setTestNow(CarbonImmutable::getTestNow());
    Illuminate\Support\Facades\Schedule::swap(new Schedule(config('app.timezone')));
    require base_path('routes/console.php');

    return collect(app(Schedule::class)->events());
}

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

it('does not schedule unavailable period registration', function (string $date) {
    $events = periodCapabilityScheduleAt($date);
    expect($events->contains(fn ($event): bool => str_contains((string) $event->command, 'mlb:train-period-models')))->toBeFalse();
    expect($events->contains(fn ($event): bool => $event->description === 'MLB: Weekly F3/F5 Model Training'))->toBeFalse();
})->with(['in season' => '2026-07-01', 'offseason' => '2026-12-15']);

it('retains supported weekly training and retained period inference without changing flags', function () {
    $enabled = config('mlb_ml.period_models.enabled');
    $events = periodCapabilityScheduleAt('2026-07-01')->keyBy('description');
    expect($events->get('MLB: Weekly Model Challenger Training')?->expression)->toBe('40 6 * * 1')
        ->and($events->get('NFL: Weekly Model Challenger Training')?->expression)->toBe('40 12 * * 2')
        ->and($events->contains(fn ($event): bool => str_contains((string) $event->command, 'mlb:run-period-shadow')))->toBeTrue()
        ->and(config('mlb_ml.period_models.enabled'))->toBe($enabled);
});
