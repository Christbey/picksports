<?php

use App\Services\Sports\SeasonStage\SeasonStageService;
use App\Services\Sports\SportsPipelineRegistry;
use App\Support\BasketballSeason;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;

it('uses the basketball ending year across the fall winter and postseason', function (string $date, int $season) {
    $this->travelTo(CarbonImmutable::parse($date, 'America/Chicago'));
    expect(BasketballSeason::forDate())->toBe($season);
    $registry = app(SportsPipelineRegistry::class);
    foreach (['nba', 'cbb', 'wcbb'] as $sport) {
        $context = app(SeasonStageService::class)->context($sport);
        expect($context->season)->toBe($season);
        $plan = $registry->pipelineSteps($sport, 'predict', $registry->context());
        $generation = collect($plan)->firstWhere('command', $sport.':generate-predictions');
        expect($generation['arguments']['--season'])->toBe($season);
    }
})->with([
    ['2026-10-20 12:00:00', 2027],
    ['2027-01-10 12:00:00', 2027],
    ['2027-03-10 12:00:00', 2027],
    ['2027-06-20 12:00:00', 2027],
    ['2027-07-01 12:00:00', 2028],
]);

it('schedules basketball predictions for the ending year with active preseason coverage and heartbeat guards', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'America/Chicago'));
    $events = collect(app(Schedule::class)->events());
    foreach (['nba', 'cbb', 'wcbb'] as $sport) {
        $daily = $events->first(fn ($event) => str_contains((string) $event->command, $sport.':generate-predictions --season='));
        expect($daily->command)->toContain('--season=2027')->and($daily->filtersPass(app()))->toBeTrue();
        $coverage = $events->firstWhere('description', strtoupper($sport).': Ensure Pregame Prediction Coverage');
        expect($coverage)->not->toBeNull()->and($coverage->expression)->toBe('*/5 * * * *')
            ->and($coverage->filtersPass(app()))->toBeTrue()
            ->and($coverage->onOneServer)->toBeTrue()->and($coverage->withoutOverlapping)->toBeTrue();
    }
    foreach (['NBA: Sync Scoreboard (Today + 7 Days)', 'CBB: Sync Current Week'] as $name) {
        expect($events->firstWhere('description', $name)->command)->toContain('--sync');
    }
    expect($events->first(fn ($event) => str_contains((string) $event->command, 'nfl:grade-predictions --season='))->command)
        ->toContain('--season=2026');
});

it('honors an explicit basketball season override in manual pipelines', function () {
    $registry = app(SportsPipelineRegistry::class);
    $steps = $registry->pipelineSteps('nba', 'predict', $registry->context('2026-10-20', 2025));
    expect(collect($steps)->firstWhere('command', 'nba:generate-predictions')['arguments']['--season'])->toBe(2025);
});
