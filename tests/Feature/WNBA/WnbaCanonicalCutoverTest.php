<?php

use App\Actions\WNBA\GenerateCanonicalPrediction;
use App\Actions\WNBA\GeneratePrediction;
use App\Actions\WNBA\UpdateLivePrediction;
use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\BetDecision;
use App\Models\PredictionEvaluation;
use App\Models\SportEvent;
use App\Models\SportEventResult;
use App\Models\User;
use App\Models\WNBA\Game;
use App\Models\WNBA\Prediction;
use App\Models\WNBA\Team;
use App\Models\WNBA\TeamMetric;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use App\Services\Predictions\HistoricalForecastArchive;
use App\Services\Predictions\PredictionDeliverySource;
use App\Services\WNBA\Predictions\WnbaCalculationReleaseRegistrar;
use App\Services\WNBA\Predictions\WnbaCalculator;
use App\Services\WNBA\Predictions\WnbaCanonicalCutoverReadinessService;
use App\Services\WNBA\Predictions\WnbaInputSnapshotBuilder;
use Laravel\Sanctum\Sanctum;

function wnbaCutoverFixture(): array
{
    $startsAt = now()->addDay()->startOfHour();
    $event = SportEvent::factory()->create([
        'sport' => 'wnba',
        'season' => 2026,
        'season_type' => '2',
        'starts_at' => $startsAt,
        'status' => 'STATUS_SCHEDULED',
    ]);
    $home = Team::factory()->create([
        'location' => 'Las Vegas',
        'name' => 'Aces',
        'abbreviation' => 'LVC',
        'elo_rating' => 1600,
    ]);
    $away = Team::factory()->create([
        'location' => 'Chicago',
        'name' => 'Sky',
        'abbreviation' => 'CHC',
        'elo_rating' => 1450,
    ]);

    foreach ([
        [$home, 106.0, 96.0, 10.0, 89.0, 3.0],
        [$away, 98.0, 104.0, -6.0, 87.0, -1.0],
    ] as [$team, $offense, $defense, $net, $tempo, $recent]) {
        TeamMetric::query()->create([
            'team_id' => $team->getKey(),
            'season' => 2026,
            'season_type' => '2',
            'wins' => 12,
            'losses' => 4,
            'offensive_efficiency' => $offense,
            'defensive_efficiency' => $defense,
            'net_rating' => $net,
            'tempo' => $tempo,
            'strength_of_schedule' => 0,
            'recent_form_rating' => $recent,
            'injury_adjusted_team_rating' => $team->elo_rating,
            'injury_total_adjustment' => 0,
            'rest_travel_fatigue' => 0,
            'calculation_date' => now()->toDateString(),
        ]);
    }

    $game = Game::factory()->create([
        'sport_event_id' => $event->getKey(),
        'home_team_id' => $home->getKey(),
        'away_team_id' => $away->getKey(),
        'season' => 2026,
        'season_type' => '2',
        'status' => 'STATUS_SCHEDULED',
        'game_date' => $event->fresh()->starts_at->utc()->toDateString(),
        'game_time' => $event->fresh()->starts_at->utc()->toTimeString(),
        'home_score' => null,
        'away_score' => null,
    ]);

    return compact('event', 'game', 'home', 'away');
}

it('preserves established WNBA outputs under the frozen full-model release', function (bool $neutral) {
    $f = wnbaCutoverFixture();
    $f['event']->update(['neutral_site' => $neutral]);
    $expected = app(GeneratePrediction::class)->preview($f['game']->fresh());
    $release = app(WnbaCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    config(['wnba.elo.home_court_advantage' => -9999]);
    $p = app(GenerateCanonicalPrediction::class)->execute($f['game']->fresh());
    expect((float) $p->markets->firstWhere('market_type', 'spread')->projected_line)->toBe(-(float) $expected['predicted_spread'])
        ->and((float) $p->markets->firstWhere('market_type', 'total')->projected_line)->toBe((float) $expected['predicted_total'])
        ->and((float) $p->markets->first(fn ($m) => $m->market_type === 'moneyline' && $m->selection === 'home')->probability)->toBe((float) $expected['win_probability'])
        ->and((float) $p->markets->firstWhere('market_type', 'spread')->confidence_score)->toBe((float) $expected['confidence_score'])
        ->and($release->input_schema_version)->toBe('wnba-pregame-v2')
        ->and($p->output_metadata['forecast_source'])->toBe('frozen_wnba_model_v1')
        ->and(config('wnba.elo.home_court_advantage'))->toBe(-9999)->and(Prediction::count())->toBe(0);
    config(['prediction_lifecycle.canonical_reads.wnba' => true]);
    expect(app(GameBettingRecommendationService::class)->forPrediction(app(PredictionDeliverySource::class)->forGame($f['game']->fresh(), 'wnba')))->toBe([])
        ->and(BetDecision::count())->toBe(0);
})->with([false, true]);

it('passes empty WNBA offseason readiness only with a full-model release and no active records', function () {
    config(['prediction_lifecycle.canonical_pipeline.wnba' => true]);
    $service = app(WnbaCanonicalCutoverReadinessService::class);
    expect($service->report(2026, true)['ready_for_cutover'])->toBeFalse();
    app(WnbaCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    expect($service->report(2026, true)['ready_for_cutover'])->toBeTrue()
        ->and($service->report(2026)['ready_for_cutover'])->toBeFalse();
    $f = wnbaCutoverFixture();
    $f['game']->update(['status' => 'STATUS_HALFTIME', 'game_date' => today()->subWeek()]);
    $r = $service->report(2026, true);
    expect($r['stale_active_game_ids'])->toBe([$f['game']->id])->and($r['readiness_blockers'])->toContain('offseason_requires_no_active_games', 'stale_active_game_records')
        ->and($service->report(2027, true)['ready_for_cutover'])->toBeFalse();
    $f['game']->update(['status' => 'STATUS_UNRESOLVED']);
    expect($service->report(2026, true)['held_unresolved_game_ids'])->toBe([$f['game']->id])
        ->and($service->report(2026, true)['ready_for_cutover'])->toBeTrue();
    $this->artisan('wnba:report-canonical-cutover-readiness', ['--season' => 2026, '--offseason' => true, '--fail-on-not-ready' => true])->assertSuccessful();
});

it('preserves WNBA historical display separately from active and betting inputs', function () {
    config(['prediction_lifecycle.canonical_reads.wnba' => true, 'subscriptions.enforce_tiers' => false]);
    $f = wnbaCutoverFixture();
    $tip = now()->subWeek();
    $f['event']->update(['starts_at' => $tip]);
    $tip = $f['event']->fresh()->starts_at->utc();
    $f['game']->update(['status' => 'STATUS_FINAL', 'game_date' => $tip->toDateString(), 'game_time' => $tip->toTimeString(), 'home_score' => 85, 'away_score' => 75]);
    Prediction::factory()->create(['game_id' => $f['game']->id, 'predicted_spread' => 3.5, 'predicted_total' => 150]);
    app(WnbaCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    expect(app(HistoricalForecastArchive::class)->forGame($f['game']->fresh(), 'wnba')['source'])->toBe('legacy_history')
        ->and(app(PredictionDeliverySource::class)->forGame($f['game']->fresh(), 'wnba'))->toBeNull();
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v2/sports/wnba/games/'.$f['game']->id)->assertSuccessful()->assertJsonPath('historical_prediction.predicted_spread', 3.5)
        ->assertJsonPath('historical_prediction.known_before_kickoff', false)->assertJsonMissingPath('historical_prediction.betting_value');
    config(['subscriptions.enforce_tiers' => true, 'subscriptions.tier_bypass_user_ids' => []]);
    $this->getJson('/api/v2/sports/wnba/games/'.$f['game']->id)->assertForbidden();
});

it('preserves WNBA live calculations and native withdrawal holds', function () {
    $f = wnbaCutoverFixture();
    $legacy = app(GeneratePrediction::class)->execute($f['game']->fresh(), false);
    app(WnbaCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $native = app(GenerateCanonicalPrediction::class)->execute($f['game']->fresh());
    $before = $legacy->fresh()->getAttributes();
    $this->travel(2)->days();
    $f['game']->update(['status' => 'STATUS_IN_PROGRESS', 'period' => 3, 'game_clock' => '5:00', 'home_score' => 60, 'away_score' => 55]);
    config(['prediction_lifecycle.canonical_reads.wnba' => false]);
    $expected = app(UpdateLivePrediction::class)->execute($f['game']->fresh());
    config(['prediction_lifecycle.canonical_reads.wnba' => true]);
    expect(app(UpdateLivePrediction::class)->execute($f['game']->fresh()))->toEqual($expected)->not->toBeNull()
        ->and($legacy->fresh()->getAttributes())->toBe($before);
    $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    expect(app(UpdateLivePrediction::class)->execute($f['game']->fresh()))->toBeNull();
});

it('replays frozen WNBA evidence without invoking the model or accepting missing outputs', function (string $field) {
    $f = wnbaCutoverFixture();
    $release = app(WnbaCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $data = CalculationReleaseData::fromModel($release);
    $snapshot = app(WnbaInputSnapshotBuilder::class)->build($f['event']->fresh(), $data);
    $calculator = app(WnbaCalculator::class);
    $expected = $calculator->calculate($snapshot, $data);
    config(['wnba.prediction' => []]);
    $this->mock(GeneratePrediction::class)->shouldNotReceive('preview');
    expect($calculator->calculate($snapshot, $data))->toEqual($expected);
    $inputs = $snapshot->inputs;
    unset($inputs['established_model']['outputs'][$field]);
    expect(fn () => $calculator->calculate(new EventInputSnapshotData($snapshot->schemaVersion, $inputs, $snapshot->capturedAt), $data))->toThrow(PredictionLifecycleException::class);
})->with(['predicted_spread', 'predicted_total', 'win_probability']);

it('requires final scores and evaluations before WNBA offseason history is ready', function () {
    config(['prediction_lifecycle.canonical_pipeline.wnba' => true]);
    $f = wnbaCutoverFixture();
    app(WnbaCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $native = app(GenerateCanonicalPrediction::class)->execute($f['game']->fresh());
    $hash = $native->output_hash;
    $this->travel(2)->days();
    $f['game']->update(['status' => 'STATUS_FINAL', 'game_clock' => '0:00', 'home_score' => 85, 'away_score' => null]);
    $this->artisan('wnba:evaluate-canonical-predictions', ['--game' => $f['game']->id])->assertSuccessful();
    expect(PredictionEvaluation::count())->toBe(0);
    $f['game']->update(['away_score' => 75]);
    expect(app(WnbaCanonicalCutoverReadinessService::class)->report(2026, true)['readiness_blockers'])->toContain('canonical_history_not_ready');
    $this->artisan('wnba:evaluate-canonical-predictions', ['--game' => $f['game']->id])->assertSuccessful();
    expect(PredictionEvaluation::count())->toBe(1)
        ->and(app(WnbaCanonicalCutoverReadinessService::class)->report(2026, true)['ready_for_cutover'])->toBeTrue();
    $f['game']->update(['home_score' => 86]);
    $this->artisan('wnba:evaluate-canonical-predictions', ['--game' => $f['game']->id])->assertSuccessful();
    expect(PredictionEvaluation::count())->toBe(2)->and(SportEventResult::count())->toBe(2)
        ->and($native->fresh()->output_hash)->toBe($hash)->and(BetDecision::count())->toBe(0);
});

it('freezes WNBA prior-season fallback consistently for evidence and the established model', function (bool $fallback) {
    config(['wnba.prediction.use_previous_season_metrics_fallback' => $fallback]);
    $f = wnbaCutoverFixture();
    TeamMetric::whereIn('team_id', [$f['home']->id, $f['away']->id])->update(['season' => 2025]);
    $expected = app(GeneratePrediction::class)->preview($f['game']->fresh());
    app(WnbaCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    config(['wnba.prediction.use_previous_season_metrics_fallback' => ! $fallback]);
    $p = app(GenerateCanonicalPrediction::class)->execute($f['game']->fresh());
    expect((float) $p->markets->firstWhere('market_type', 'spread')->projected_line)->toBe(-(float) $expected['predicted_spread'])
        ->and($p->calculationRun->inputSnapshot->inputs['home']['metrics'] === null)->toBe(! $fallback)
        ->and(config('wnba.prediction.use_previous_season_metrics_fallback'))->toBe(! $fallback);
})->with([false, true]);
