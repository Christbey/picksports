<?php

use App\Actions\WCBB\GenerateCanonicalPrediction;
use App\Actions\WCBB\GeneratePrediction;
use App\Actions\WCBB\UpdateLivePrediction;
use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Sports\ReadModels\LivePregameForecast;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\BetDecision;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\PredictionFeatureSnapshot;
use App\Models\SportEvent;
use App\Models\SportEventResult;
use App\Models\User;
use App\Models\WCBB\Game;
use App\Models\WCBB\Prediction;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamMetric;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use App\Services\Predictions\CalculationReleaseManager;
use App\Services\Predictions\CanonicalPredictionEvaluator;
use App\Services\Predictions\HistoricalForecastArchive;
use App\Services\Predictions\LivePredictionStore;
use App\Services\Predictions\PredictionDeliverySource;
use App\Services\WCBB\Predictions\WcbbCalculationReleaseRegistrar;
use App\Services\WCBB\Predictions\WcbbCalculator;
use App\Services\WCBB\Predictions\WcbbCanonicalCutoverReadinessService;
use App\Services\WCBB\Predictions\WcbbInputSnapshotBuilder;
use App\Services\WCBB\Predictions\WcbbPregameInputReadiness;
use Laravel\Sanctum\Sanctum;

function wcbbCutoverFixture(bool $neutral = false): array
{
    $event = SportEvent::factory()->create(['sport' => 'wcbb', 'season' => 2027, 'season_type' => '2', 'status' => 'STATUS_SCHEDULED', 'starts_at' => now()->addDay()->startOfHour(), 'neutral_site' => $neutral]);
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

it('preserves WCBB ensemble outputs and neutral-site behavior under frozen configuration and native ownership', function (bool $neutral) {
    config(['prediction_lifecycle.canonical_pipeline.wcbb' => true, 'prediction_lifecycle.canonical_reads.wcbb' => true]);
    $f = wcbbCutoverFixture($neutral);
    $expected = app(GeneratePrediction::class)->preview($f['game']->fresh());
    $release = app(WcbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    config(['wcbb.elo.home_court_advantage' => -9999]);
    $p = app(GenerateCanonicalPrediction::class)->execute($f['game']->fresh());
    expect((float) $p->markets->firstWhere('market_type', 'spread')->projected_line)->toBe(-(float) $expected['predicted_spread'])
        ->and((float) $p->markets->firstWhere('market_type', 'total')->projected_line)->toBe((float) $expected['predicted_total'])
        ->and((float) $p->markets->first(fn ($m) => $m->market_type === 'moneyline' && $m->selection === 'home')->probability)->toBe((float) $expected['win_probability'])
        ->and((float) $p->markets->firstWhere('market_type', 'spread')->confidence_score)->toBe((float) $expected['confidence_score'])
        ->and($p->output_metadata['forecast_source'])->toBe('frozen_wcbb_model_v1')
        ->and($release->input_schema_version)->toBe('wcbb-pregame-v2')
        ->and(config('wcbb.elo.home_court_advantage'))->toBe(-9999)
        ->and(Prediction::count())->toBe(0);
    $delivery = app(PredictionDeliverySource::class)->forGame($f['game']->fresh(), 'wcbb');
    expect(app(GameBettingRecommendationService::class)->forPrediction($delivery))->toBe([])
        ->and(BetDecision::where('is_public', true)->count())->toBe(0);
})->with([false, true]);

it('selects the effective WCBB replacement release and keeps placeholder holds out of eligible coverage', function () {
    config(['prediction_lifecycle.canonical_pipeline.wcbb' => true]);
    $f = wcbbCutoverFixture();
    $old = app(WcbbCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subHour()->toImmutable());
    app(CalculationReleaseManager::class)->retire($old, now()->subMinute()->toImmutable());
    $release = app(WcbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $heldEvent = SportEvent::factory()->create(['sport' => 'wcbb', 'starts_at' => $f['event']->starts_at, 'season' => 2027]);
    $held = Game::factory()->create(['sport_event_id' => $heldEvent->id, 'home_team_id' => $f['home']->id,
        'away_team_id' => Team::factory()->create(['school' => 'TBD', 'abbreviation' => 'TBD', 'espn_id' => '-123'])->id,
        'season' => 2027, 'status' => 'STATUS_SCHEDULED', 'game_date' => $f['game']->game_date, 'game_time' => $f['game']->game_time]);
    expect(app(GeneratePrediction::class)->preview($held->fresh()))->toBeNull();
    expect(fn () => app(GenerateCanonicalPrediction::class)->execute($held->fresh()))->toThrow(PredictionLifecycleException::class, 'placeholder');
    $this->artisan('wcbb:generate-canonical-predictions', ['--season' => 2027])->assertSuccessful();
    $r = app(WcbbCanonicalCutoverReadinessService::class)->report(2027);
    expect($r['selected_release_id'])->toBe($release->id)->and($r['scheduled_slate_event_count'])->toBe(2)
        ->and($r['held_placeholder_game_ids'])->toBe([$held->id])->and($r['eligible_event_count'])->toBe(1)
        ->and($r['slate_event_count'])->toBe(1)->and($r['read_transport_ready'])->toBeTrue()
        ->and($r['ready_for_cutover'])->toBeTrue()->and(CanonicalPrediction::count())->toBe(1);
});

it('holds WCBB kickoff and neutral-site mismatches without publishing', function (string $variant) {
    $f = wcbbCutoverFixture(true);
    app(WcbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    if ($variant === 'neutral') {
        $f['event']->forceFill(['neutral_site' => false])->save();
    } else {
        $f['event']->forceFill(['starts_at' => $f['event']->starts_at->addHour()])->save();
    }
    expect(fn () => app(GenerateCanonicalPrediction::class)->execute($f['game']->fresh()))->toThrow(PredictionLifecycleException::class, 'identity');
    $r = app(WcbbCanonicalCutoverReadinessService::class)->report(2027);
    expect($r['read_transport_ready'])->toBeFalse()->and($r['identity_mismatch_game_ids'])->toBe([$f['game']->id])
        ->and($r['readiness_blockers'])->toContain('canonical_game_identity_mismatch')->and(CanonicalPrediction::count())->toBe(0);
})->with(['neutral', 'tipoff']);

it('rejects missing frozen WCBB outputs and replays without mutable model reads', function (string $field) {
    $f = wcbbCutoverFixture();
    $release = app(WcbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $data = CalculationReleaseData::fromModel($release);
    $snapshot = app(WcbbInputSnapshotBuilder::class)->build($f['event']->fresh(), $data);
    $calculator = app(WcbbCalculator::class);
    $expected = $calculator->calculate($snapshot, $data);
    config(['wcbb.prediction' => []]);
    $this->mock(GeneratePrediction::class)->shouldNotReceive('preview');
    expect($calculator->calculate($snapshot, $data))->toEqual($expected);
    $inputs = $snapshot->inputs;
    $inputs['established_model']['outputs'][$field] = null;
    expect(fn () => $calculator->calculate(new EventInputSnapshotData($snapshot->schemaVersion, $inputs, $snapshot->capturedAt), $data))->toThrow(PredictionLifecycleException::class);
})->with(['predicted_spread', 'predicted_total', 'win_probability']);

it('blocks WCBB read cutover for an in-flight legacy baseline and unlinked games', function () {
    $f = wcbbCutoverFixture();
    Prediction::factory()->create(['game_id' => $f['game']->id]);
    $f['event']->forceFill(['starts_at' => now()->subMinutes(5)])->save();
    $r = app(WcbbCanonicalCutoverReadinessService::class)->report(2027);
    expect($r['inflight_legacy_game_ids'])->toBe([$f['game']->id])->and($r['read_transport_ready'])->toBeFalse();
    $f['event']->forceFill(['starts_at' => now()->addDay()])->save();
    $f['game']->forceFill(['sport_event_id' => null])->save();
    expect(app(WcbbCanonicalCutoverReadinessService::class)->report(2027)['unlinked_slate_game_ids'])->toBe([$f['game']->id]);
});

it('preserves WCBB history with separate provenance and never borrows another sport release', function () {
    config(['prediction_lifecycle.canonical_reads.wcbb' => true, 'subscriptions.enforce_tiers' => false]);
    $f = wcbbCutoverFixture();
    $tip = now()->utc()->subWeek()->startOfHour();
    $f['game']->forceFill(['status' => 'STATUS_FINAL', 'game_date' => $tip->toDateString(), 'game_time' => $tip->toTimeString(), 'home_score' => 80, 'away_score' => 70])->save();
    $legacy = Prediction::factory()->create(['game_id' => $f['game']->id, 'predicted_spread' => 4.5, 'predicted_total' => 150]);
    CalculationRelease::factory()->approved()->create(['sport' => 'nba', 'phase' => 'pregame', 'effective_at' => now()->subDay()]);
    $archive = app(HistoricalForecastArchive::class);
    expect($archive->forGame($f['game']->fresh(), 'wcbb'))->toBeNull();
    app(WcbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $snapshot = PredictionFeatureSnapshot::create(['sport' => 'wcbb', 'prediction_table' => 'wcbb_predictions', 'prediction_id' => $legacy->id, 'game_id' => $f['game']->id,
        'model_version' => 'observed', 'feature_version' => 'test', 'blend_version' => 'test', 'features' => [], 'outputs' => ['predicted_spread' => 3.5, 'predicted_total' => 145],
        'generated_at' => $tip->subHour(), 'features_available_at' => $tip->subHours(2), 'game_start_at' => $tip, 'pregame_safe' => true, 'availability_status' => 'observed_pregame']);
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v2/sports/wcbb/games/'.$f['game']->id)->assertSuccessful()
        ->assertJsonPath('historical_prediction.source', 'legacy_archive')->assertJsonPath('historical_prediction.snapshot_id', $snapshot->id)
        ->assertJsonPath('historical_prediction.predicted_spread', 3.5)->assertJsonPath('historical_prediction.known_before_kickoff', true)
        ->assertJsonMissingPath('historical_prediction.betting_value');
    $snapshot->forceFill(['pregame_safe' => false, 'availability_status' => 'after_game_start'])->save();
    expect($archive->forGame($f['game']->fresh(), 'wcbb')['source'])->toBe('legacy_history')
        ->and($archive->forGame($f['game']->fresh(), 'wcbb')['known_before_kickoff'])->toBeFalse()
        ->and($archive->forGame($f['game']->fresh(), 'wcbb')['predicted_spread'])->toBe(4.5)
        ->and($archive->forGame($f['game']->fresh(), 'nba'))->toBeNull()->and(CanonicalPrediction::count())->toBe(0);
    config(['subscriptions.enforce_tiers' => true, 'subscriptions.tier_bypass_user_ids' => []]);
    $this->getJson('/api/v2/sports/wcbb/games/'.$f['game']->id)->assertForbidden();
});

it('bounds default WCBB generation while retaining explicit game and date targeting and readable reports', function () {
    $f = wcbbCutoverFixture();
    $event = SportEvent::factory()->create(['sport' => 'wcbb', 'season' => 2027, 'starts_at' => now()->addDays(30), 'neutral_site' => false]);
    $tip = $event->fresh()->starts_at->utc();
    $far = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => $f['home']->id, 'away_team_id' => $f['away']->id,
        'season' => 2027, 'season_type' => '2', 'status' => 'STATUS_SCHEDULED', 'game_date' => $tip->toDateString(), 'game_time' => $tip->toTimeString(), 'neutral_site' => false]);
    app(WcbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $this->artisan('wcbb:generate-canonical-predictions', ['--season' => 2027])->assertSuccessful();
    expect(CanonicalPrediction::where('sport_event_id', $event->id)->count())->toBe(0);
    $this->artisan('wcbb:generate-canonical-predictions', ['--game' => $far->id])->assertSuccessful();
    $this->artisan('wcbb:generate-canonical-predictions', ['--date' => $far->game_date->toDateString()])->assertSuccessful();
    expect(CanonicalPrediction::where('sport_event_id', $event->id)->where('publication_state', 'published')->count())->toBe(1);
    $this->artisan('wcbb:report-canonical-cutover-readiness', ['--season' => 2027])->assertSuccessful();
    $this->artisan('wcbb:report-canonical-cutover-readiness', ['--season' => 2027, '--json' => true, '--fail-on-not-ready' => true])->assertFailed();
});

it('freezes the established WCBB prior-season fallback policy in both model capture and canonical evidence', function (bool $fallback) {
    config(['wcbb.prediction.use_previous_season_metrics_fallback' => $fallback]);
    $f = wcbbCutoverFixture();
    TeamMetric::whereIn('team_id', [$f['home']->id, $f['away']->id])->update(['season' => 2026]);
    $expected = app(GeneratePrediction::class)->preview($f['game']->fresh());
    $release = app(WcbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    config(['wcbb.prediction.use_previous_season_metrics_fallback' => ! $fallback]);
    $p = app(GenerateCanonicalPrediction::class)->execute($f['game']->fresh());
    expect((float) $p->markets->firstWhere('market_type', 'spread')->projected_line)->toBe(-(float) $expected['predicted_spread'])
        ->and((float) $p->markets->firstWhere('market_type', 'total')->projected_line)->toBe((float) $expected['predicted_total'])
        ->and($release->configuration['inputs']['use_previous_season_metrics_fallback'])->toBe($fallback);
    $metrics = $p->calculationRun->inputSnapshot->inputs['home']['metrics'];
    expect($metrics === null)->toBe(! $fallback);
})->with([false, true]);

it('holds unusable WCBB metric rows explicitly without changing the established model or hiding valid coverage', function (string $field) {
    config(['prediction_lifecycle.canonical_pipeline.wcbb' => true]);
    $held = wcbbCutoverFixture();
    TeamMetric::where('team_id', $held['home']->id)->update([$field => 0]);
    $valid = wcbbCutoverFixture();
    app(WcbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    expect(fn () => app(GenerateCanonicalPrediction::class)->execute($held['game']->fresh()))->toThrow(PredictionLifecycleException::class, 'unusable');
    $this->artisan('wcbb:generate-canonical-predictions', ['--season' => 2027])->assertSuccessful();
    $report = app(WcbbCanonicalCutoverReadinessService::class)->report(2027);
    expect($report['held_input_game_ids'])->toBe([$held['game']->id])->and($report['held_placeholder_game_ids'])->toBe([])
        ->and($report['slate_event_count'])->toBe(1)->and($report['full_model_forecast_event_count'])->toBe(1)
        ->and($report['read_transport_ready'])->toBeTrue()->and(CanonicalPrediction::where('sport_event_id', $held['event']->id)->count())->toBe(0);
})->with(['offensive_efficiency', 'defensive_efficiency', 'tempo']);

it('checks WCBB input holds using the release frozen fallback policy and restores mutable configuration', function () {
    config(['wcbb.prediction.use_previous_season_metrics_fallback' => false]);
    $f = wcbbCutoverFixture();
    TeamMetric::whereIn('team_id', [$f['home']->id, $f['away']->id])->update(['season' => 2026, 'tempo' => 0]);
    $release = app(WcbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    config(['wcbb.prediction.use_previous_season_metrics_fallback' => true]);
    expect(app(WcbbPregameInputReadiness::class)->holds($f['game']->fresh(), $release))->toBe([])
        ->and(config('wcbb.prediction.use_previous_season_metrics_fallback'))->toBeTrue();
    $this->artisan('wcbb:generate-canonical-predictions', ['--season' => 2027])->assertSuccessful();
    expect(CanonicalPrediction::count())->toBe(1);
});

it('preserves WCBB live projections from full-model forecasts and fails closed after withdrawal', function () {
    $f = wcbbCutoverFixture();
    $legacy = app(GeneratePrediction::class)->execute($f['game']->fresh(), false);
    app(WcbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $native = app(GenerateCanonicalPrediction::class)->execute($f['game']->fresh());
    $legacyBefore = $legacy->fresh()->getAttributes();
    $nativeBefore = $native->fresh()->getAttributes();
    $this->travel(2)->days();
    $f['game']->forceFill(['status' => 'STATUS_IN_PROGRESS', 'period' => 2, 'game_clock' => '5:00', 'home_score' => 42, 'away_score' => 39])->save();
    $action = app(UpdateLivePrediction::class);
    config(['prediction_lifecycle.canonical_reads.wcbb' => false]);
    $old = $action->execute($f['game']->fresh());
    config(['prediction_lifecycle.canonical_reads.wcbb' => true]);
    expect($action->execute($f['game']->fresh()))->toEqual($old)->not->toBeNull()
        ->and($legacy->fresh()->getAttributes())->toBe($legacyBefore)->and($native->fresh()->getAttributes())->toBe($nativeBefore);
    $native->forceFill(['publication_state' => 'withdrawn', 'withdrawn_at' => now()])->save();
    expect($action->execute($f['game']->fresh()))->toBeNull()->and($legacy->fresh()->getAttributes())->toBe($legacyBefore);
});

it('does not revive expired WCBB cutover baselines after selecting canonical reads', function () {
    config(['prediction_lifecycle.canonical_pipeline.wcbb' => true, 'prediction_lifecycle.canonical_reads.wcbb' => false]);
    $f = wcbbCutoverFixture();
    $legacy = Prediction::factory()->create(['game_id' => $f['game']->id, 'predicted_spread' => 3.2, 'predicted_total' => 150, 'win_probability' => 0.65]);
    $tip = now()->subMinutes(10);
    $f['event']->forceFill(['starts_at' => $tip])->save();
    $tip = $f['event']->fresh()->starts_at->utc();
    $f['game']->forceFill(['status' => 'STATUS_IN_PROGRESS', 'game_date' => $tip->toDateString(), 'game_time' => $tip->toTimeString(),
        'period' => 1, 'game_clock' => '10:00', 'home_score' => 21, 'away_score' => 20])->save();
    $store = app(LivePredictionStore::class);
    $baseline = $store->baseline($f['game']->fresh());
    $store->record($f['game']->fresh(), $baseline, ['live_predicted_total' => 145], 'live', 'test', now()->subMinutes(3));
    app(WcbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinutes(2)->toImmutable());
    $legacyBefore = $legacy->fresh()->getAttributes();
    $expected = app(UpdateLivePrediction::class)->execute($f['game']->fresh());
    config(['prediction_lifecycle.canonical_reads.wcbb' => true]);
    expect($expected)->not->toBeNull();
    expect(app(UpdateLivePrediction::class)->execute($f['game']->fresh()))->toBeNull()
        ->and($store->fields($f['game']->fresh()))->toBe(LivePredictionStore::EMPTY_FIELDS)
        ->and(app(PredictionDeliverySource::class)->forGame($f['game']->fresh(), 'wcbb'))->toBeNull()
        ->and($legacy->fresh()->getAttributes())->toBe($legacyBefore)->and(CanonicalPrediction::count())->toBe(0)
        ->and(BetDecision::count())->toBe(0);
    $r = app(WcbbCanonicalCutoverReadinessService::class)->report(2027);
    expect($r['inflight_legacy_game_ids'])->toBe([$f['game']->id])->and($r['read_transport_ready'])->toBeFalse();
    $f['game']->forceFill(['status' => 'STATUS_FINAL'])->save();
    expect($store->baseline($f['game']->fresh()))->toBeNull()
        ->and($store->fields($f['game']->fresh()))->toBe(LivePredictionStore::EMPTY_FIELDS);
});

it('rejects unsafe WCBB live compatibility anchors and never rescues withdrawn native forecasts', function (string $variant) {
    config(['prediction_lifecycle.canonical_reads.wcbb' => false]);
    $f = wcbbCutoverFixture();
    Prediction::factory()->create(['game_id' => $f['game']->id, 'predicted_spread' => 3.2, 'predicted_total' => 150, 'win_probability' => 0.65]);
    $tip = now()->subMinutes(10);
    $f['event']->forceFill(['starts_at' => $tip])->save();
    $tip = $f['event']->fresh()->starts_at->utc();
    $f['game']->forceFill(['status' => 'STATUS_IN_PROGRESS', 'game_date' => $tip->toDateString(), 'game_time' => $tip->toTimeString(), 'period' => 1])->save();
    $store = app(LivePredictionStore::class);
    $baseline = $store->baseline($f['game']->fresh())->toArray();
    if ($variant === 'invalid') {
        $baseline['predicted_total'] = 0;
    }
    if ($variant === 'other_table') {
        $baseline['prediction_table'] = 'nba_predictions';
    }
    if ($variant !== 'missing') {
        $store->record($f['game']->fresh(), LivePregameForecast::fromArray($baseline), [], 'live', 'test', $variant === 'late' ? now() : now()->subMinutes(3));
    }
    app(WcbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinutes(2)->toImmutable());
    if ($variant === 'post_cutover') {
        $f['event']->forceFill(['starts_at' => now()->subMinute()])->save();
    }
    if ($variant === 'withdrawn') {
        CanonicalPrediction::factory()->create(['sport' => 'wcbb', 'sport_event_id' => $f['event']->id, 'phase' => 'pregame', 'publication_state' => 'withdrawn']);
    }
    config(['prediction_lifecycle.canonical_reads.wcbb' => true]);
    expect($store->baseline($f['game']->fresh()))->toBeNull()
        ->and($store->fields($f['game']->fresh()))->toBe(LivePredictionStore::EMPTY_FIELDS)
        ->and(BetDecision::count())->toBe(0);
})->with(['missing', 'late', 'invalid', 'other_table', 'post_cutover', 'withdrawn']);

it('retains bad-metric holds after a WCBB game finishes without inventing a retrospective pregame forecast', function () {
    config(['prediction_lifecycle.canonical_pipeline.wcbb' => true, 'prediction_lifecycle.canonical_reads.wcbb' => true]);
    $f = wcbbCutoverFixture();
    $legacy = Prediction::factory()->create(['game_id' => $f['game']->id]);
    $before = $legacy->fresh()->getAttributes();
    TeamMetric::where('team_id', $f['away']->id)->update(['offensive_efficiency' => 0, 'defensive_efficiency' => 0, 'tempo' => 0]);
    $release = app(WcbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    expect(app(WcbbPregameInputReadiness::class)->holds($f['game']->fresh(), $release))->toHaveCount(3);
    $this->travel(2)->days();
    $f['game']->update(['status' => 'STATUS_FINAL', 'home_score' => 86, 'away_score' => 46]);
    expect(fn () => app(GenerateCanonicalPrediction::class)->execute($f['game']->fresh()))->toThrow(PredictionLifecycleException::class)
        ->and(CanonicalPrediction::count())->toBe(0)->and(CalculationRun::count())->toBe(0)
        ->and(BetDecision::count())->toBe(0)->and($legacy->fresh()->getAttributes())->toBe($before);
});

it('uses verified prior-season metrics for a zeroed WCBB row before tipoff under the recovery release', function () {
    config([
        'wcbb.prediction.use_previous_season_metrics_fallback' => false,
        'prediction_lifecycle.canonical_pipeline.wcbb' => true,
        'prediction_lifecycle.canonical_reads.wcbb' => true,
    ]);
    $fixture = wcbbCutoverFixture();
    $homeMetric = TeamMetric::where('team_id', $fixture['home']->id)->firstOrFail();
    $previousMetric = $homeMetric->replicate()->fill(['season' => 2026, 'tempo' => 73]);
    $previousMetric->save();
    $homeMetric->update(['offensive_efficiency' => 0, 'defensive_efficiency' => 0, 'tempo' => 0]);

    $registrar = app(WcbbCalculationReleaseRegistrar::class);
    $original = $registrar->register('1.1.0', effectiveAt: now()->subHour()->toImmutable());
    expect(fn () => app(GenerateCanonicalPrediction::class)->execute($fixture['game']->fresh()))
        ->toThrow(PredictionLifecycleException::class, 'unusable');

    $effectiveAt = now()->subMinute()->toImmutable();
    app(CalculationReleaseManager::class)->retire($original, $effectiveAt);
    $registrar->register('1.1.1', effectiveAt: $effectiveAt);
    $this->artisan('sports:ensure-basketball-predictions wcbb --season=2027')
        ->expectsOutput('Pregame coverage: 1 generated; 0 still missing.')
        ->assertSuccessful();
    $forecast = CanonicalPrediction::where('sport_event_id', $fixture['event']->id)->sole();
    $inputs = $forecast->calculationRun->inputSnapshot->inputs;

    expect($forecast->publication_state)->toBe('published')
        ->and(data_get($inputs, 'home.metrics.record_season'))->toBe(2026)
        ->and(data_get($inputs, 'home.metrics.tempo'))->toEqual(73)
        ->and(data_get($inputs, 'away.metrics.record_season'))->toBe(2027)
        ->and($original->fresh()->configuration['inputs']['use_previous_season_metrics_fallback'])->toBeFalse();
});

it('keeps WCBB forecasts held when a zeroed current row has no usable prior-season replacement', function () {
    $fixture = wcbbCutoverFixture();
    TeamMetric::where('team_id', $fixture['home']->id)->update(['tempo' => 0]);
    $release = app(WcbbCalculationReleaseRegistrar::class)->register('1.1.1', effectiveAt: now()->subMinute()->toImmutable());

    expect(app(WcbbPregameInputReadiness::class)->holds($fixture['game']->fresh(), $release))->toHaveCount(1)
        ->and(fn () => app(GenerateCanonicalPrediction::class)->execute($fixture['game']->fresh()))
        ->toThrow(PredictionLifecycleException::class, 'unusable')
        ->and(CanonicalPrediction::count())->toBe(0);
});

it('grades stored WCBB endpoint probabilities without rewriting the forecast', function (float $probability) {
    $fixture = wcbbCutoverFixture();
    $preview = app(GeneratePrediction::class)->preview($fixture['game']->fresh());
    $preview['win_probability'] = $probability;
    $this->partialMock(GeneratePrediction::class)->shouldReceive('preview')->once()->andReturn($preview);
    app(WcbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $forecast = app(GenerateCanonicalPrediction::class)->execute($fixture['game']->fresh());
    $result = SportEventResult::factory()->create([
        'sport_event_id' => $fixture['event']->id, 'home_score' => 90, 'away_score' => 70,
    ]);

    $evaluation = app(CanonicalPredictionEvaluator::class)->evaluate($forecast, $result);
    expect($evaluation->errors['log_loss'])->toBeNull()
        ->and($evaluation->errors['probability_metric_status'])->toBe('boundary_probability_log_loss_unavailable')
        ->and($evaluation->errors['brier_score'])->toEqual((1 - $probability) ** 2)
        ->and((float) $forecast->markets->first(fn ($market) => $market->market_type === 'moneyline' && $market->selection === 'home')->probability)->toBe($probability);
})->with([0.0, 1.0]);
