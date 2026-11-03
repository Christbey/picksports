<?php

use App\Actions\MLB\EvaluateCanonicalPrediction;
use App\Actions\MLB\GenerateCanonicalPrediction;
use App\Actions\MLB\GeneratePrediction;
use App\Actions\MLB\UpdateLivePrediction;
use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CanonicalPrediction;
use App\Models\MLB\Game;
use App\Models\MLB\GameWeather;
use App\Models\MLB\Player;
use App\Models\MLB\Prediction as LegacyMlbPrediction;
use App\Models\MLB\Team;
use App\Models\MLB\TeamMetric;
use App\Models\PredictionEvaluation;
use App\Models\SportEvent;
use App\Models\SportEventResult;
use App\Models\User;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use App\Services\MLB\Predictions\MlbCalculationReleaseRegistrar;
use App\Services\MLB\Predictions\MlbCalculator;
use App\Services\MLB\Predictions\MlbCanonicalCutoverReadinessService;
use App\Services\MLB\Predictions\MlbInputSnapshotBuilder;
use App\Services\Predictions\PredictionDeliverySource;
use Laravel\Sanctum\Sanctum;

/** @return array<string,mixed> */
function mlbCutoverFixture(): array
{
    $startsAt = now()->addDay()->startOfHour();
    $event = SportEvent::factory()->create([
        'sport' => 'mlb', 'season' => 2026, 'season_type' => '2', 'starts_at' => $startsAt,
        'status' => 'STATUS_SCHEDULED', 'neutral_site' => false,
    ]);
    $home = Team::factory()->create(['location' => 'Chicago', 'name' => 'Cubs', 'abbreviation' => 'CHC', 'elo_rating' => 1580]);
    $away = Team::factory()->create(['location' => 'Pittsburgh', 'name' => 'Pirates', 'abbreviation' => 'PIT', 'elo_rating' => 1440]);
    $homePitcher = Player::factory()->create(['team_id' => $home->getKey(), 'espn_id' => 'home-starter', 'full_name' => 'Home Starter', 'elo_rating' => 1620]);
    $awayPitcher = Player::factory()->create(['team_id' => $away->getKey(), 'espn_id' => 'away-starter', 'full_name' => 'Away Starter', 'elo_rating' => 1400]);

    foreach ([[$home, 5.2, 3.8, 1.4], [$away, 3.7, 5.1, -1.4]] as [$team, $runs, $allowed, $differential]) {
        TeamMetric::query()->create([
            'team_id' => $team->getKey(), 'season' => 2026, 'season_type' => '2', 'wins' => 45, 'losses' => 30,
            'offensive_rating' => 110, 'pitching_rating' => 105, 'defensive_rating' => 100,
            'runs_per_game' => $runs, 'runs_allowed_per_game' => $allowed, 'run_differential_per_game' => $differential,
            'strength_of_schedule' => 0, 'recent_form_rating' => $differential,
            'injury_adjusted_team_rating' => $team->elo_rating, 'injury_total_adjustment' => 0,
            'rest_travel_fatigue' => 0, 'calculation_date' => now()->toDateString(),
        ]);
    }

    $game = Game::factory()->create([
        'sport_event_id' => $event->getKey(), 'home_team_id' => $home->getKey(), 'away_team_id' => $away->getKey(),
        'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_SCHEDULED', 'game_date' => $startsAt,
        'home_score' => null, 'away_score' => null, 'venue_name' => 'Wrigley Field',
        'game_time' => $startsAt->toTimeString(),
        'probable_home_pitcher_espn_id' => $homePitcher->espn_id, 'probable_away_pitcher_espn_id' => $awayPitcher->espn_id,
    ]);
    GameWeather::query()->create([
        'game_id' => $game->getKey(), 'provider' => 'test', 'observed_at' => now()->subMinute(),
        'temperature_f' => 82, 'wind_speed_mph' => 10, 'is_indoor' => false, 'roof_status' => 'open',
    ]);

    return compact('event', 'game', 'home', 'away', 'homePitcher', 'awayPitcher');
}

it('preserves the established MLB model under frozen canonical transport and keeps approvals held', function (bool $neutral) {
    config(['prediction_lifecycle.canonical_pipeline.mlb' => true, 'prediction_lifecycle.canonical_reads.mlb' => true]);
    $f = mlbCutoverFixture();
    $f['event']->forceFill(['neutral_site' => $neutral])->save();
    $expected = app(GeneratePrediction::class)->preview($f['game']->fresh());
    $release = app(MlbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    config(['mlb.elo.home_field_advantage' => -9999]);
    $p = app(GenerateCanonicalPrediction::class)->execute($f['game']->fresh());
    expect((float) $p->markets->firstWhere('market_type', 'spread')->projected_line)->toBe(-(float) $expected['predicted_spread'])
        ->and((float) $p->markets->firstWhere('market_type', 'total')->projected_line)->toBe((float) $expected['predicted_total'])
        ->and((float) $p->markets->first(fn ($m) => $m->market_type === 'moneyline' && $m->selection === 'home')->probability)->toBe((float) $expected['win_probability'])
        ->and((float) $p->markets->firstWhere('market_type', 'spread')->confidence_score)->toBe((float) $expected['confidence_score'])
        ->and($p->output_metadata['forecast_source'])->toBe('frozen_mlb_model_v1')
        ->and($release->input_schema_version)->toBe('mlb-pregame-v2')
        ->and(config('mlb.elo.home_field_advantage'))->toBe(-9999)
        ->and(LegacyMlbPrediction::count())->toBe(0);
    $delivery = app(PredictionDeliverySource::class)->forGame($f['game']->fresh(), 'mlb');
    expect(app(GameBettingRecommendationService::class)->forPrediction($delivery))->toBe([]);
    $snapshot = $p->calculationRun->inputSnapshot;
    expect((float) data_get($snapshot->inputs, 'pitching.home.elo'))->toBe((float) $expected['home_pitcher_elo']);
    $data = CalculationReleaseData::fromModel($release);
    $frozen = new EventInputSnapshotData($snapshot->schema_version, $snapshot->inputs, $snapshot->captured_at);
    $output = app(MlbCalculator::class)->calculate($frozen, $data);
    $this->mock(GeneratePrediction::class)->shouldNotReceive('preview');
    config(['mlb.prediction' => []]);
    expect(app(MlbCalculator::class)->calculate($frozen, $data))->toEqual($output);
})->with([false, true]);

it('does not accept the smaller MLB rules release as established model readiness', function () {
    $f = mlbCutoverFixture();
    app(MlbCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    app(GenerateCanonicalPrediction::class)->execute($f['game']);
    expect(app(MlbCanonicalCutoverReadinessService::class)->report(2026)['readiness_blockers'])->toContain('established_model_release_required');
});

it('requires explicit offseason mode and holds stale active records even when unlinked', function () {
    config(['prediction_lifecycle.canonical_pipeline.mlb' => true]);
    app(MlbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $readiness = app(MlbCanonicalCutoverReadinessService::class);
    expect($readiness->report(2026)['readiness_blockers'])->toContain('no_upcoming_slate')
        ->and($readiness->report(2026, true)['ready_for_cutover'])->toBeTrue();
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'season' => 2026, 'sport_event_id' => null, 'status' => 'STATUS_IN_PROGRESS', 'game_date' => now()->subMonth()]);
    expect($readiness->report(2026, true)['readiness_blockers'])->toContain('stale_active_game_records', 'offseason_requires_no_active_games');
    $game->forceFill(['status' => 'STATUS_UNRESOLVED'])->save();
    expect($readiness->report(2026, true)['ready_for_cutover'])->toBeTrue()
        ->and($readiness->report(2026, true)['held_unresolved_game_ids'])->toBe([$game->id]);
});

it('blocks MLB kickoff identity mismatches and expired generation', function () {
    $f = mlbCutoverFixture();
    app(MlbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $f['event']->forceFill(['starts_at' => $f['event']->starts_at->addHour()])->save();
    expect(fn () => app(GenerateCanonicalPrediction::class)->execute($f['game']->fresh()))->toThrow(PredictionLifecycleException::class, 'identity');
    expect(app(MlbCanonicalCutoverReadinessService::class)->report(2026)['identity_mismatch_game_ids'])->toBe([$f['game']->id]);
});

it('preserves MLB history separately from active forecasts and never settles unresolved games', function () {
    config(['prediction_lifecycle.canonical_reads.mlb' => true, 'subscriptions.enforce_tiers' => false]);
    $f = mlbCutoverFixture();
    app(MlbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $f['game']->forceFill(['status' => 'STATUS_UNRESOLVED', 'game_date' => now()->subWeek(), 'game_time' => '12:00:00'])->save();
    $legacy = LegacyMlbPrediction::query()->create(['game_id' => $f['game']->id, 'predicted_spread' => 1.5, 'predicted_total' => 8.5, 'win_probability' => .6]);
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v2/sports/mlb/games/'.$f['game']->id.'/page')->assertSuccessful()
        ->assertJsonPath('data.prediction', null)->assertJsonPath('data.historical_prediction.source', 'legacy_history')
        ->assertJsonPath('data.historical_prediction.prediction_id', $legacy->id)
        ->assertJsonPath('data.historical_prediction.known_before_kickoff', false);
    app(EvaluateCanonicalPrediction::class)->execute($f['game']->fresh());
    expect(PredictionEvaluation::count())->toBe(0)->and(CanonicalPrediction::count())->toBe(0);
});

it('preserves MLB live projections and canonical finalization correction history', function () {
    config(['prediction_lifecycle.canonical_reads.mlb' => false]);
    $f = mlbCutoverFixture();
    $expected = app(GeneratePrediction::class)->preview($f['game']->fresh());
    LegacyMlbPrediction::query()->create(['game_id' => $f['game']->id, ...array_intersect_key($expected, array_flip(['predicted_spread', 'predicted_total', 'win_probability', 'confidence_score']))]);
    app(MlbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $native = app(GenerateCanonicalPrediction::class)->execute($f['game']->fresh());
    $immutable = $native->getAttributes();
    $this->travel(2)->days();
    $f['game']->forceFill(['status' => 'STATUS_IN_PROGRESS', 'inning' => 5, 'home_score' => 3, 'away_score' => 2])->save();
    $legacyLive = app(UpdateLivePrediction::class)->execute($f['game']->fresh());
    config(['prediction_lifecycle.canonical_reads.mlb' => true]);
    $nativeLive = app(UpdateLivePrediction::class)->execute($f['game']->fresh());
    expect($nativeLive)->toEqual($legacyLive)->and($native->fresh()->getAttributes())->toBe($immutable);
    $evaluator = app(EvaluateCanonicalPrediction::class);
    expect($evaluator->execute($f['game']->fresh()))->toBeNull();
    $f['game']->forceFill(['status' => 'STATUS_FINAL', 'home_score' => 6, 'away_score' => 3])->save();
    $evaluator->execute($f['game']->fresh());
    $evaluator->execute($f['game']->fresh());
    expect(PredictionEvaluation::count())->toBe(1);
    $f['game']->forceFill(['home_score' => 7])->save();
    $evaluator->execute($f['game']->fresh());
    expect(PredictionEvaluation::count())->toBe(2)->and(SportEventResult::count())->toBe(2)
        ->and($native->fresh()->getAttributes())->toBe($immutable);
});

it('rejects incomplete MLB frozen outputs without consulting mutable legacy forecasts', function (string $field) {
    $f = mlbCutoverFixture();
    $release = app(MlbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $data = CalculationReleaseData::fromModel($release);
    $snapshot = app(MlbInputSnapshotBuilder::class)->build($f['event']->fresh(), $data);
    $inputs = $snapshot->inputs;
    $inputs['established_model']['outputs'][$field] = null;
    expect(fn () => app(MlbCalculator::class)->calculate(
        new EventInputSnapshotData($snapshot->schemaVersion, $inputs, $snapshot->capturedAt), $data,
    ))->toThrow(PredictionLifecycleException::class);
})->with(['predicted_spread', 'predicted_total', 'win_probability']);
