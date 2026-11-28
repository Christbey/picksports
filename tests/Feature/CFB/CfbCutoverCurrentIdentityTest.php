<?php

use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\SportEvent;
use App\Services\CFB\Predictions\CfbCalculationReleaseRegistrar;
use App\Services\CFB\Predictions\CfbCanonicalCutoverReadinessService;
use App\Services\Sports\SportEventIdentitySynchronizer;
use App\Support\CFB\CfbGameStart;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 27)->startOfDay());
    app(CfbCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
});

it('holds CFB cutover when a scoped scheduled game has no confirmed current clock', function () {
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'season' => 2026, 'week' => 1, 'starts_at' => null]);
    Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'sport_event_id' => $event->id, 'season' => 2026, 'week' => 1,
        'game_date' => now()->addDay()->toDateString(), 'game_time' => '00:00:00', 'status' => 'STATUS_SCHEDULED',
        'kickoff_observation' => null]);
    $report = app(CfbCanonicalCutoverReadinessService::class)->report(2026, 1);
    expect($report['eligible_event_count'])->toBe(0)->and($report['ready_for_cutover'])->toBeFalse();
});

it('holds CFB cutover when a confirmed scheduled game has no canonical event binding', function () {
    Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'espn_event_id' => 'cutover-unlinked', 'sport_event_id' => null,
        'season' => 2026, 'week' => 1, 'status' => 'STATUS_SCHEDULED',
        ...CfbGameStart::attributes(now()->addDay()->utc()->toIso8601String(), [], 'espn_schedule', 'cutover-unlinked')]);
    expect(app(CfbCanonicalCutoverReadinessService::class)->report(2026, 1)['ready_for_cutover'])->toBeFalse();
});

it('preserves the existing empty off-slate diagnostic when no scheduled game needs identity admission', function () {
    expect(app(CfbCanonicalCutoverReadinessService::class)->report(2026, 1)['ready_for_cutover'])->toBeTrue();
});

it('keeps other CFB seasons and weeks outside the requested readiness scope', function () {
    Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'season' => 2025, 'week' => 1, 'status' => 'STATUS_SCHEDULED', 'sport_event_id' => null, 'kickoff_observation' => null]);
    Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'season' => 2026, 'week' => 2, 'status' => 'STATUS_SCHEDULED', 'sport_event_id' => null, 'kickoff_observation' => null]);
    expect(app(CfbCanonicalCutoverReadinessService::class)->report(2026, 1)['ready_for_cutover'])->toBeTrue();
});

it('admits a matching known current CFB identity while retaining missing forecast coverage holds', function () {
    $start = now()->addDay()->utc();
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'season' => 2026, 'week' => 1, 'starts_at' => $start]);
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'espn_event_id' => 'cutover-known', 'sport_event_id' => $event->id, 'season' => 2026, 'week' => 1, 'status' => 'STATUS_SCHEDULED',
        ...CfbGameStart::attributes($start->toIso8601String(), [], 'espn_schedule', 'cutover-known')]);
    app(SportEventIdentitySynchronizer::class)->sync('cfb', $game);
    $report = app(CfbCanonicalCutoverReadinessService::class)->report(2026, 1);
    expect($report['unknown_current_kickoff_game_ids'])->toBe([])->and($report['current_event_identity_hold_game_ids'])->toBe([])
        ->and($report['eligible_event_count'])->toBe(1)->and($report['missing_safe_prediction_count'])->toBe(1)->and($report['ready_for_cutover'])->toBeFalse();
});

it('reports a known CFB clock whose linked event no longer has the same instant', function () {
    $start = now()->addDay()->utc();
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'season' => 2026, 'week' => 1, 'starts_at' => $start->copy()->addHour()]);
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'espn_event_id' => 'cutover-rebound', 'sport_event_id' => $event->id, 'season' => 2026, 'week' => 1, 'status' => 'STATUS_DELAYED',
        ...CfbGameStart::attributes($start->toIso8601String(), [], 'espn_schedule', 'cutover-rebound')]);
    $report = app(CfbCanonicalCutoverReadinessService::class)->report(2026, 1);
    expect($report['unknown_current_kickoff_game_ids'])->toBe([])->and($report['current_event_identity_hold_game_ids'])->toBe([$game->id])
        ->and($report['ready_for_cutover'])->toBeFalse();
});

it('retains the empty diagnostic for completed unknown-clock history', function () {
    Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'week' => 1, 'status' => 'STATUS_FINAL', 'sport_event_id' => null, 'kickoff_observation' => null]);
    $report = app(CfbCanonicalCutoverReadinessService::class)->report(2026, 1);
    expect($report['unknown_current_kickoff_game_ids'])->toBe([])->and($report['current_event_identity_hold_game_ids'])->toBe([])
        ->and($report['ready_for_cutover'])->toBeTrue();
});

it('admits the current event wall clock produced by the actual identity synchronizer', function () {
    config(['app.timezone' => 'America/Chicago']);
    $start = now()->addDay()->utc();
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'espn_event_id' => 'cutover-synchronized', 'sport_event_id' => null, 'season' => 2026, 'week' => 1, 'status' => 'STATUS_SCHEDULED',
        ...CfbGameStart::attributes($start->toIso8601String(), [], 'espn_schedule', 'cutover-synchronized')]);
    $event = app(SportEventIdentitySynchronizer::class)->sync('cfb', $game);
    expect($event->starts_at->equalTo($start))->toBeTrue();
    $report = app(CfbCanonicalCutoverReadinessService::class)->report(2026, 1);
    expect($report['unknown_current_kickoff_game_ids'])->toBe([])->and($report['current_event_identity_hold_game_ids'])->toBe([]);
});
