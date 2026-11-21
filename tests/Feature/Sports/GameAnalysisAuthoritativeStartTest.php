<?php

use App\Models\AnalysisWorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PredictionReviewFixtures;

beforeEach(function () {
    $this->travelTo('2026-11-20 12:00:00 America/Chicago');
    Http::preventStrayRequests();
    Queue::fake();
    config(['prediction_review.enabled' => true, 'prediction_review.sports.nba' => true]);
});

afterEach(fn () => $this->travelBack());

it('reconciles the authoritative event horizon with legacy fallback only when event time is absent', function (string $scenario, bool $eligible) {
    [$game] = PredictionReviewFixtures::game('nba');
    if ($scenario === 'future event stale game') {
        $game->update(['game_date' => now()->subDays(2)->toDateString()]);
    } elseif ($scenario === 'past event future game') {
        $game->sportEvent->update(['starts_at' => now()->subHour()]);
    } elseif ($scenario === 'event outside horizon') {
        $game->sportEvent->update(['starts_at' => now()->addDays(20)]);
    } elseif ($scenario === 'null event time') {
        $game->sportEvent->update(['starts_at' => null]);
    } elseif ($scenario === 'no linked event') {
        $game->update(['sport_event_id' => null]);
    }
    foreach (['sports:reconcile-game-analysis', 'sports:reconcile-prediction-reviews'] as $command) {
        expect(Artisan::call($command, ['--sport' => ['nba'], '--days-forward' => 0, '--dry-run' => true]))->toBe(0);
        $output = Artisan::output();
        expect($output)->toContain($command === 'sports:reconcile-game-analysis'
            ? ($eligible ? '1 eligible' : '0 eligible') : ($eligible ? 'eligible 1' : 'eligible 0'));
    }
    expect(AnalysisWorkItem::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    ['future event stale game', true],
    ['past event future game', false],
    ['event outside horizon', false],
    ['null event time', true],
    ['no linked event', true],
]);

it('uses Central business-day boundaries in the configured event storage timezone', function (string $appTimezone, string $eventStart, bool $eligible) {
    $originalTimezone = date_default_timezone_get();
    config(['app.timezone' => $appTimezone, 'sports.business_timezone' => 'America/Chicago']);
    date_default_timezone_set($appTimezone);
    try {
        $this->travelTo(CarbonImmutable::parse('2026-11-20T00:05:00-06:00'));
        [$game] = PredictionReviewFixtures::game('nba');
        $game->sportEvent->update(['starts_at' => CarbonImmutable::parse($eventStart)->setTimezone($appTimezone)]);
        // This mutable timestamp stays inside the day: it must not rescue an
        // authoritative event at the following local midnight.
        $game->update(['game_date' => '2026-11-20', 'game_time' => '23:00:00']);
        foreach (['sports:reconcile-game-analysis', 'sports:reconcile-prediction-reviews'] as $command) {
            expect(Artisan::call($command, ['--sport' => ['nba'], '--days-forward' => 0, '--dry-run' => true]))->toBe(0);
            expect(Artisan::output())->toContain($command === 'sports:reconcile-game-analysis'
                ? ($eligible ? '1 eligible' : '0 eligible') : ($eligible ? 'eligible 1' : 'eligible 0'));
        }
        expect(AnalysisWorkItem::query()->count())->toBe(0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    } finally {
        date_default_timezone_set($originalTimezone);
    }
})->with([
    ['America/Chicago', '2026-11-20T00:30:00-06:00', true],
    ['America/Chicago', '2026-11-20T23:59:00-06:00', true],
    ['America/Chicago', '2026-11-21T00:00:00-06:00', false],
    ['UTC', '2026-11-20T00:30:00-06:00', true],
    ['UTC', '2026-11-20T23:59:00-06:00', true],
    ['UTC', '2026-11-21T00:00:00-06:00', false],
]);
