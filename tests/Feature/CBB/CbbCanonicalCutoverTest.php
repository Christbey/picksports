<?php

use App\Actions\CBB\GenerateCanonicalPrediction;
use App\Actions\CBB\GeneratePrediction;
use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\BetDecision;
use App\Models\CalculationRelease;
use App\Models\CanonicalPrediction;
use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use App\Models\CBB\Team;
use App\Models\CBB\TeamMetric;
use App\Models\PredictionFeatureSnapshot;
use App\Models\SportEvent;
use App\Models\User;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use App\Services\CBB\Predictions\CbbCalculationReleaseRegistrar;
use App\Services\CBB\Predictions\CbbCalculator;
use App\Services\CBB\Predictions\CbbCanonicalCutoverReadinessService;
use App\Services\CBB\Predictions\CbbInputSnapshotBuilder;
use App\Services\Predictions\CalculationReleaseManager;
use App\Services\Predictions\HistoricalForecastArchive;
use App\Services\Predictions\PredictionDeliverySource;
use Laravel\Sanctum\Sanctum;

function cbbCutoverFixture(bool $neutral = false): array
{
    $event = SportEvent::factory()->create(['sport' => 'cbb', 'season' => 2027, 'season_type' => '2', 'status' => 'STATUS_SCHEDULED', 'starts_at' => now()->addDay()->startOfHour(), 'neutral_site' => $neutral]);
    $tip = $event->fresh()->starts_at->utc();
    $home = Team::factory()->create(['elo_rating' => 1610]);
    $away = Team::factory()->create(['elo_rating' => 1480]);
    foreach ([[$home, 112, 98, 14], [$away, 103, 109, -6]] as [$team, $offense, $defense, $net]) {
        TeamMetric::query()->create(['team_id' => $team->id, 'season' => 2027, 'wins' => 4, 'losses' => 2,
            'offensive_efficiency' => $offense, 'defensive_efficiency' => $defense, 'net_rating' => $net, 'tempo' => 71,
            'strength_of_schedule' => 0, 'recent_form_rating' => 0, 'injury_adjusted_team_rating' => $team->elo_rating,
            'injury_total_adjustment' => 0, 'rest_travel_fatigue' => 0, 'calculation_date' => now()->toDateString()]);
    }
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2027, 'season_type' => '2', 'status' => 'STATUS_SCHEDULED', 'game_date' => $tip->toDateString(), 'game_time' => $tip->toTimeString(), 'neutral_site' => $neutral, 'home_score' => null, 'away_score' => null]);

    return compact('event', 'game', 'home', 'away');
}

it('preserves CBB ensemble outputs and neutral-site behavior under frozen configuration and native ownership', function (bool $neutral) {
    config(['prediction_lifecycle.canonical_pipeline.cbb' => true, 'prediction_lifecycle.canonical_reads.cbb' => true]);
    $f = cbbCutoverFixture($neutral);
    $expected = app(GeneratePrediction::class)->preview($f['game']->fresh());
    $release = app(CbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    config(['cbb.elo.home_court_advantage' => -9999]);
    $p = app(GenerateCanonicalPrediction::class)->execute($f['game']->fresh());
    expect((float) $p->markets->firstWhere('market_type', 'spread')->projected_line)->toBe(-(float) $expected['predicted_spread'])
        ->and((float) $p->markets->firstWhere('market_type', 'total')->projected_line)->toBe((float) $expected['predicted_total'])
        ->and((float) $p->markets->first(fn ($m) => $m->market_type === 'moneyline' && $m->selection === 'home')->probability)->toBe((float) $expected['win_probability'])
        ->and($p->output_metadata['forecast_source'])->toBe('frozen_cbb_model_v1')
        ->and($release->input_schema_version)->toBe('cbb-pregame-v2')
        ->and(config('cbb.elo.home_court_advantage'))->toBe(-9999)
        ->and(Prediction::count())->toBe(0);
    $delivery = app(PredictionDeliverySource::class)->forGame($f['game']->fresh(), 'cbb');
    expect(app(GameBettingRecommendationService::class)->forPrediction($delivery))->toBe([])
        ->and(BetDecision::where('is_public', true)->count())->toBe(0);
})->with([false, true]);

it('selects the effective CBB replacement release and keeps placeholder holds out of eligible coverage', function () {
    config(['prediction_lifecycle.canonical_pipeline.cbb' => true]);
    $f = cbbCutoverFixture();
    $old = app(CbbCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subHour()->toImmutable());
    app(CalculationReleaseManager::class)->retire($old, now()->subMinute()->toImmutable());
    $release = app(CbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $heldEvent = SportEvent::factory()->create(['sport' => 'cbb', 'starts_at' => $f['event']->starts_at, 'season' => 2027]);
    $held = Game::factory()->create(['sport_event_id' => $heldEvent->id, 'home_team_id' => $f['home']->id,
        'away_team_id' => Team::factory()->create(['school' => 'TBD', 'abbreviation' => 'TBD', 'espn_id' => '-123'])->id,
        'season' => 2027, 'status' => 'STATUS_SCHEDULED', 'game_date' => $f['game']->game_date, 'game_time' => $f['game']->game_time]);
    expect(app(GeneratePrediction::class)->preview($held->fresh()))->toBeNull();
    expect(fn () => app(GenerateCanonicalPrediction::class)->execute($held->fresh()))->toThrow(PredictionLifecycleException::class, 'placeholder');
    $this->artisan('cbb:generate-canonical-predictions', ['--season' => 2027])->assertSuccessful();
    $r = app(CbbCanonicalCutoverReadinessService::class)->report(2027);
    expect($r['selected_release_id'])->toBe($release->id)->and($r['scheduled_slate_event_count'])->toBe(2)
        ->and($r['held_placeholder_game_ids'])->toBe([$held->id])->and($r['eligible_event_count'])->toBe(1)
        ->and($r['slate_event_count'])->toBe(1)->and($r['read_transport_ready'])->toBeTrue()
        ->and($r['ready_for_cutover'])->toBeTrue()->and(CanonicalPrediction::count())->toBe(1);
});

it('holds CBB kickoff and neutral-site mismatches without publishing', function (string $variant) {
    $f = cbbCutoverFixture(true);
    app(CbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    if ($variant === 'neutral') {
        $f['event']->forceFill(['neutral_site' => false])->save();
    } else {
        $f['event']->forceFill(['starts_at' => $f['event']->starts_at->addHour()])->save();
    }
    expect(fn () => app(GenerateCanonicalPrediction::class)->execute($f['game']->fresh()))->toThrow(PredictionLifecycleException::class, 'identity');
    $r = app(CbbCanonicalCutoverReadinessService::class)->report(2027);
    expect($r['read_transport_ready'])->toBeFalse()->and($r['identity_mismatch_game_ids'])->toBe([$f['game']->id])
        ->and($r['readiness_blockers'])->toContain('canonical_game_identity_mismatch')->and(CanonicalPrediction::count())->toBe(0);
})->with(['neutral', 'tipoff']);

it('rejects missing frozen CBB outputs and replays without mutable model reads', function (string $field) {
    $f = cbbCutoverFixture();
    $release = app(CbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $data = CalculationReleaseData::fromModel($release);
    $snapshot = app(CbbInputSnapshotBuilder::class)->build($f['event']->fresh(), $data);
    $calculator = app(CbbCalculator::class);
    $expected = $calculator->calculate($snapshot, $data);
    config(['cbb.prediction' => []]);
    $this->mock(GeneratePrediction::class)->shouldNotReceive('preview');
    expect($calculator->calculate($snapshot, $data))->toEqual($expected);
    $inputs = $snapshot->inputs;
    $inputs['established_model']['outputs'][$field] = null;
    expect(fn () => $calculator->calculate(new EventInputSnapshotData($snapshot->schemaVersion, $inputs, $snapshot->capturedAt), $data))->toThrow(PredictionLifecycleException::class);
})->with(['predicted_spread', 'predicted_total', 'win_probability']);

it('blocks CBB read cutover for an in-flight legacy baseline and unlinked games', function () {
    $f = cbbCutoverFixture();
    Prediction::factory()->create(['game_id' => $f['game']->id]);
    $f['event']->forceFill(['starts_at' => now()->subMinutes(5)])->save();
    $r = app(CbbCanonicalCutoverReadinessService::class)->report(2027);
    expect($r['inflight_legacy_game_ids'])->toBe([$f['game']->id])->and($r['read_transport_ready'])->toBeFalse();
    $f['event']->forceFill(['starts_at' => now()->addDay()])->save();
    $f['game']->forceFill(['sport_event_id' => null])->save();
    expect(app(CbbCanonicalCutoverReadinessService::class)->report(2027)['unlinked_slate_game_ids'])->toBe([$f['game']->id]);
});

it('preserves CBB history with separate provenance and never borrows another sport release', function () {
    config(['prediction_lifecycle.canonical_reads.cbb' => true, 'subscriptions.enforce_tiers' => false]);
    $f = cbbCutoverFixture();
    $tip = now()->utc()->subWeek()->startOfHour();
    $f['game']->forceFill(['status' => 'STATUS_FINAL', 'game_date' => $tip->toDateString(), 'game_time' => $tip->toTimeString(), 'home_score' => 80, 'away_score' => 70])->save();
    $legacy = Prediction::factory()->create(['game_id' => $f['game']->id, 'predicted_spread' => 4.5, 'predicted_total' => 150]);
    CalculationRelease::factory()->approved()->create(['sport' => 'nba', 'phase' => 'pregame', 'effective_at' => now()->subDay()]);
    $archive = app(HistoricalForecastArchive::class);
    expect($archive->forGame($f['game']->fresh(), 'cbb'))->toBeNull();
    app(CbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $snapshot = PredictionFeatureSnapshot::create(['sport' => 'cbb', 'prediction_table' => 'cbb_predictions', 'prediction_id' => $legacy->id, 'game_id' => $f['game']->id,
        'model_version' => 'observed', 'feature_version' => 'test', 'blend_version' => 'test', 'features' => [], 'outputs' => ['predicted_spread' => 3.5, 'predicted_total' => 145],
        'generated_at' => $tip->subHour(), 'features_available_at' => $tip->subHours(2), 'game_start_at' => $tip, 'pregame_safe' => true, 'availability_status' => 'observed_pregame']);
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v2/sports/cbb/games/'.$f['game']->id)->assertSuccessful()
        ->assertJsonPath('historical_prediction.source', 'legacy_archive')->assertJsonPath('historical_prediction.snapshot_id', $snapshot->id)
        ->assertJsonPath('historical_prediction.predicted_spread', 3.5)->assertJsonPath('historical_prediction.known_before_kickoff', true)
        ->assertJsonMissingPath('historical_prediction.betting_value');
    $snapshot->forceFill(['pregame_safe' => false, 'availability_status' => 'after_game_start'])->save();
    expect($archive->forGame($f['game']->fresh(), 'cbb')['source'])->toBe('legacy_history')
        ->and($archive->forGame($f['game']->fresh(), 'cbb')['known_before_kickoff'])->toBeFalse()
        ->and($archive->forGame($f['game']->fresh(), 'cbb')['predicted_spread'])->toBe(4.5)
        ->and($archive->forGame($f['game']->fresh(), 'nba'))->toBeNull()->and(CanonicalPrediction::count())->toBe(0);
    config(['subscriptions.enforce_tiers' => true, 'subscriptions.tier_bypass_user_ids' => []]);
    $this->getJson('/api/v2/sports/cbb/games/'.$f['game']->id)->assertForbidden();
});

it('bounds default CBB generation while retaining explicit game and date targeting and readable reports', function () {
    $f = cbbCutoverFixture();
    $event = SportEvent::factory()->create(['sport' => 'cbb', 'season' => 2027, 'starts_at' => now()->addDays(30), 'neutral_site' => false]);
    $tip = $event->fresh()->starts_at->utc();
    $far = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => $f['home']->id, 'away_team_id' => $f['away']->id,
        'season' => 2027, 'season_type' => '2', 'status' => 'STATUS_SCHEDULED', 'game_date' => $tip->toDateString(), 'game_time' => $tip->toTimeString(), 'neutral_site' => false]);
    app(CbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $this->artisan('cbb:generate-canonical-predictions', ['--season' => 2027])->assertSuccessful();
    expect(CanonicalPrediction::where('sport_event_id', $event->id)->count())->toBe(0);
    $this->artisan('cbb:generate-canonical-predictions', ['--game' => $far->id])->assertSuccessful();
    $this->artisan('cbb:generate-canonical-predictions', ['--date' => $far->game_date->toDateString()])->assertSuccessful();
    expect(CanonicalPrediction::where('sport_event_id', $event->id)->where('publication_state', 'published')->count())->toBe(1);
    $this->artisan('cbb:report-canonical-cutover-readiness', ['--season' => 2027])->assertSuccessful();
    $this->artisan('cbb:report-canonical-cutover-readiness', ['--season' => 2027, '--json' => true, '--fail-on-not-ready' => true])->assertFailed();
});

it('freezes the established CBB prior-season fallback policy in both model capture and canonical evidence', function (bool $fallback) {
    config(['cbb.prediction.use_previous_season_metrics_fallback' => $fallback]);
    $f = cbbCutoverFixture();
    TeamMetric::whereIn('team_id', [$f['home']->id, $f['away']->id])->update(['season' => 2026]);
    $expected = app(GeneratePrediction::class)->preview($f['game']->fresh());
    $release = app(CbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    config(['cbb.prediction.use_previous_season_metrics_fallback' => ! $fallback]);
    $p = app(GenerateCanonicalPrediction::class)->execute($f['game']->fresh());
    expect((float) $p->markets->firstWhere('market_type', 'spread')->projected_line)->toBe(-(float) $expected['predicted_spread'])
        ->and((float) $p->markets->firstWhere('market_type', 'total')->projected_line)->toBe((float) $expected['predicted_total'])
        ->and($release->configuration['inputs']['use_previous_season_metrics_fallback'])->toBe($fallback);
    $metrics = $p->calculationRun->inputSnapshot->inputs['home']['metrics'];
    expect($metrics === null)->toBe(! $fallback);
})->with([false, true]);
