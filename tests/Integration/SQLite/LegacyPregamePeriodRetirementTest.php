<?php

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\MLB\Game;
use App\Models\ModelArtifact;
use App\Models\PredictionFeatureSnapshot;
use App\Models\PredictionMarket;
use App\Models\ShadowModelOutput;
use App\Models\SportEvent;
use App\Services\Api\V2\SportContextResolver;
use App\Services\MLB\MlbChallengerShadowService;
use App\Services\MLB\MlbPeriodFeatureBuilder;
use App\Services\MLB\MlbPeriodModelInferenceService;
use App\Services\MLB\MlbPeriodShadowService;
use App\Services\Predictions\CanonicalShadowInputs;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Http;
use Tests\Support\ObservedMlbGameFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->travelTo('2026-10-28 12:00:00');
    Http::preventStrayRequests();
    config(['mlb_ml.mode' => 'shadow', 'mlb_ml.shadow.enabled' => true, 'mlb_ml.period_models.enabled' => true]);
});
afterEach(function () {
    $this->travelBack();
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

function standalonePregameRetirementFixture(string $sport, ?CalculationRelease $release = null): array
{
    config(["prediction_lifecycle.canonical_pipeline.{$sport}" => true, "prediction_lifecycle.canonical_reads.{$sport}" => true]);
    $context = app(SportContextResolver::class)->resolve($sport);
    $teamClass = $context->models['team'];
    $gameClass = $context->models['game'];
    $event = SportEvent::factory()->create(['sport' => $sport, 'season' => 2026, 'starts_at' => now()->addDay()]);
    $game = $gameClass::factory()->create(['season' => 2026, 'season_type' => '2', 'sport_event_id' => $event->id,
        'status' => 'STATUS_SCHEDULED', 'game_date' => $event->starts_at->toDateString(), 'game_time' => $event->starts_at->format('H:i:s'),
        'home_team_id' => $teamClass::factory(), 'away_team_id' => $teamClass::factory()]);
    if ($game instanceof Game) {
        $game = ObservedMlbGameFixture::observe($game, $event->starts_at);
    }
    $release ??= CalculationRelease::factory()->approved()->create(['sport' => $sport, 'phase' => 'pregame']);
    $inputAttributes = ['sport' => $sport, 'sport_event_id' => $event->id,
        'phase' => 'pregame', 'pregame_safety_status' => 'verified', 'captured_at' => now(), 'cutoff_at' => now(),
        'inputs' => ['event' => ['starts_at' => $event->starts_at->toIso8601String()], 'home' => ['team_id' => $game->home_team_id, 'elo' => 1550], 'away' => ['team_id' => $game->away_team_id, 'elo' => 1490],
            'pitching' => ['home' => ['elo' => 1600, 'espn_id' => '100'], 'away' => ['elo' => 1450, 'espn_id' => '200']]]];
    if ($game instanceof Game) {
        $inputAttributes = ObservedMlbGameFixture::snapshotAttributes($game, $inputAttributes['inputs'] ?? []);
    }
    $input = EventInputSnapshot::factory()->create($inputAttributes);
    $run = CalculationRun::factory()->create(['output_hash' => str_repeat('a', 64), 'sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['output_hash' => str_repeat('a', 64), 'sport' => $sport, 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'publication_state' => 'draft', 'phase' => 'pregame',
        'generated_at' => now(), 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ([['moneyline', 'home', null, 0.7], ['spread', 'home', -4.0, null], ['total', 'combined', 9.0, null]] as [$type, $side, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type,
            'selection' => $side, 'projected_line' => $line, 'probability' => $probability]);
    }
    $native->update(['publication_state' => 'published', 'published_at' => now()]);

    return [$game, $native->refresh(), $input];
}

it('selects canonical MLB shadow and period inputs without any legacy feature snapshots', function () {
    [$game, $native, $input] = standalonePregameRetirementFixture('mlb');
    $snapshots = app(CanonicalShadowInputs::class)->forGames('mlb', $game->id);
    expect($snapshots)->toHaveCount(1)
        ->and($snapshots->sole()->lineage['event_input_snapshot_id'])->toBe($input->id)
        ->and($snapshots->sole()->outputs['predicted_spread'])->toBe(4.0);
    foreach ([MlbChallengerShadowService::class, MlbPeriodShadowService::class] as $service) {
        $method = new ReflectionMethod($service, 'canonicalSnapshots');
        expect($method->invoke(app($service), $game->id, 1)->sole()->id)->toBe($native->id);
    }
    $features = app(MlbPeriodFeatureBuilder::class)->liveFeaturesForGames(collect([$game]));
    expect($features[$game->id]['first_3_moneyline']['feature_home_pitcher_elo'])->toBe(1600.0)
        ->and(PredictionFeatureSnapshot::query()->count())->toBe(0);
    $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    expect(app(CanonicalShadowInputs::class)->forGames('mlb', $game->id))->toBeEmpty()
        ->and(app(MlbPeriodFeatureBuilder::class)->liveFeaturesForGames(collect([$game]))[$game->id]['first_3_moneyline']['feature_home_pitcher_elo'])->toBeNull();
});

it('runs native MLB period inference without creating a legacy prediction snapshot', function () {
    [$game, $native] = standalonePregameRetirementFixture('mlb');
    $training = app(ModelRunRecorder::class)->create(sport: 'mlb', runType: 'training', modelVersion: 'period', featureVersion: 'period', blendVersion: 'period');
    $artifact = ModelArtifact::query()->create(['training_run_id' => $training->id, 'sport' => 'mlb', 'market_type' => 'multi_market',
        'model_type' => 'mlb_period_bundle', 'model_version' => 'period', 'feature_version' => 'period', 'dataset_hash' => str_repeat('d', 64),
        'artifact_path' => 'test-period', 'artifact_hash' => str_repeat('e', 64), 'status' => 'challenger']);
    $output = ['market_type' => 'first_3_moneyline', 'conditional_home_win_probability' => 0.625,
        'conditional_away_win_probability' => 0.375, 'home_win_probability' => 0.5, 'away_win_probability' => 0.3,
        'tie_probability' => 0.2, 'fair_home_price' => -167, 'fair_away_price' => 167, 'uncertainty' => 0.1,
        'model_name' => 'period', 'calibration_method' => 'test', 'model_run_id' => $training->id,
        'dataset_hash' => str_repeat('d', 64), 'feature_hash' => str_repeat('f', 64)];
    $this->mock(MlbPeriodModelInferenceService::class)->shouldReceive('predict')->once()->andReturn([$output]);
    $report = app(MlbPeriodShadowService::class)->run(artifactId: $artifact->id, gameId: $game->id);
    expect($report['outputs_created'])->toBe(1)
        ->and(ShadowModelOutput::query()->sole()->canonical_prediction_id)->toBe($native->id)
        ->and(ShadowModelOutput::query()->sole()->prediction_feature_snapshot_id)->toBeNull()
        ->and(PredictionFeatureSnapshot::query()->count())->toBe(0);
});

it('keeps the existing generation command as a canonical entry point after retirement', function (string $sport) {
    [$game, $native] = standalonePregameRetirementFixture($sport);
    $action = 'App\\Actions\\'.strtoupper($sport).'\\GenerateCanonicalPrediction';
    $this->mock($action)->shouldReceive('execute')->once()->withArgs(fn ($actual) => $actual->id === $game->id)->andReturn($native);
    $this->artisan("{$sport}:generate-predictions", ['--season' => 2026])->assertSuccessful();
    expect(PredictionFeatureSnapshot::query()->count())->toBe(0);
    $legacyClass = app(SportContextResolver::class)->resolve($sport)->models['prediction'];
    expect($legacyClass::query()->count())->toBe(0);
})->with(['nba', 'cbb', 'wcbb', 'wnba', 'mlb', 'cfb', 'nfl']);
