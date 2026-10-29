<?php

use App\Actions\CBB\RecalculateTournamentOutlook;
use App\Actions\ESPN\MLB\SyncGamesFromScoreboard;
use App\Actions\MLB\GenerateCanonicalPrediction;
use App\Actions\MLB\GeneratePrediction;
use App\Actions\Validation\Checks\PredictionCompletenessCheck;
use App\Jobs\NFL\EvaluateShadowPrediction;
use App\Models\BetDecision;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CBB\Prediction;
use App\Models\EventInputSnapshot;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
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
use App\Services\MLB\MlbTabularModelInferenceService;
use App\Services\NFL\NflFullHistoricalShadowInferenceService;
use App\Services\NFL\NflPredictionPublisher;
use App\Services\NFL\Simulation\GameSimulationService;
use App\Services\Predictions\CanonicalShadowInputs;
use App\Services\Predictions\ModelRunRecorder;
use App\Services\Predictions\PredictionDeliverySource;
use App\Services\Sports\SportsPipelineRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-10-28 12:00:00');
    Http::preventStrayRequests();
});
afterEach(fn () => $this->travelBack());

function pregameRetirementFixture(string $sport, ?CalculationRelease $release = null): array
{
    config(["prediction_lifecycle.canonical_pipeline.{$sport}" => true, "prediction_lifecycle.canonical_reads.{$sport}" => true]);
    $context = app(SportContextResolver::class)->resolve($sport);
    $teamClass = $context->models['team'];
    $gameClass = $context->models['game'];
    $event = SportEvent::factory()->create(['sport' => $sport, 'season' => 2026, 'starts_at' => now()->addDay()]);
    $game = $gameClass::factory()->create(['season' => 2026, 'season_type' => '2', 'sport_event_id' => $event->id,
        'status' => 'STATUS_SCHEDULED', 'game_date' => $event->starts_at->toDateString(), 'game_time' => $event->starts_at->format('H:i:s'),
        'home_team_id' => $teamClass::factory(), 'away_team_id' => $teamClass::factory()]);
    $release ??= CalculationRelease::factory()->approved()->create(['sport' => $sport, 'phase' => 'pregame']);
    $input = EventInputSnapshot::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id,
        'phase' => 'pregame', 'pregame_safety_status' => 'verified', 'captured_at' => now(), 'cutoff_at' => now(),
        'inputs' => ['home' => ['elo' => 1550], 'away' => ['elo' => 1490],
            'pitching' => ['home' => ['elo' => 1600, 'espn_id' => '100'], 'away' => ['elo' => 1450, 'espn_id' => '200']]]]);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'publication_state' => 'draft', 'phase' => 'pregame',
        'generated_at' => now(), 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ([['moneyline', 'home', null, 0.7], ['spread', 'home', -4.0, null], ['total', 'combined', 9.0, null]] as [$type, $side, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type,
            'selection' => $side, 'projected_line' => $line, 'probability' => $probability]);
    }
    $native->update(['publication_state' => 'published', 'published_at' => now()]);

    return [$game, $native->refresh(), $input];
}

it('retires active legacy writes only after both pipeline and readers select canonical', function (string $sport) {
    [$game] = pregameRetirementFixture($sport);
    $source = app(PredictionDeliverySource::class);
    expect($source->legacyWriterRetired($sport))->toBeTrue();
    $writer = 'App\\Actions\\'.strtoupper($sport).'\\'.($sport === 'nfl' ? 'GeneratePredictionFromHistoricalElo' : 'GeneratePrediction');
    expect(fn () => app($writer)->execute($game))->toThrow(LogicException::class, 'Legacy pregame writes are retired');
    expect(PredictionFeatureSnapshot::query()->count())->toBe(0);
    config(["prediction_lifecycle.canonical_reads.{$sport}" => false]);
    expect($source->legacyWriterRetired($sport))->toBeFalse();
    config(["prediction_lifecycle.canonical_reads.{$sport}" => true, "prediction_lifecycle.canonical_pipeline.{$sport}" => false]);
    expect($source->legacyWriterRetired($sport))->toBeFalse();
})->with(['nba', 'nfl', 'cbb', 'wcbb', 'wnba', 'mlb', 'cfb']);

it('routes the active pipeline to canonical generation while retaining upstream and historical grading', function (string $sport) {
    config(["prediction_lifecycle.canonical_pipeline.{$sport}" => true, "prediction_lifecycle.canonical_reads.{$sport}" => true]);
    $registry = app(SportsPipelineRegistry::class);
    $commands = collect($registry->pipelineSteps($sport, 'predict', $registry->context(date: '2026-10-28', season: 2026)))->pluck('command');
    expect($commands)->toContain("{$sport}:generate-canonical-predictions", "{$sport}:grade-predictions", "{$sport}:calculate-elo")
        ->not->toContain("{$sport}:generate-predictions");
})->with(['nba', 'cbb', 'wcbb', 'wnba', 'mlb', 'cfb']);

it('keeps the existing generation command as a canonical entry point after retirement', function (string $sport) {
    [$game, $native] = pregameRetirementFixture($sport);
    $action = 'App\\Actions\\'.strtoupper($sport).'\\GenerateCanonicalPrediction';
    $this->mock($action)->shouldReceive('execute')->once()->withArgs(fn ($actual) => $actual->id === $game->id)->andReturn($native);
    $this->artisan("{$sport}:generate-predictions", ['--season' => 2026])->assertSuccessful();
    expect(PredictionFeatureSnapshot::query()->count())->toBe(0);
    $legacyClass = app(SportContextResolver::class)->resolve($sport)->models['prediction'];
    expect($legacyClass::query()->count())->toBe(0);
})->with(['nba', 'cbb', 'wcbb', 'wnba', 'mlb', 'cfb', 'nfl']);

it('selects canonical MLB shadow and period inputs without any legacy feature snapshots', function () {
    [$game, $native, $input] = pregameRetirementFixture('mlb');
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

it('records native shadow lineage idempotently and keeps unvalidated native shadow decisions on hold', function () {
    [$game, $native] = pregameRetirementFixture('mlb');
    $snapshot = app(CanonicalShadowInputs::class)->forGames('mlb', $game->id)->sole();
    $trainingRun = app(ModelRunRecorder::class)->create(sport: 'mlb', runType: 'training', modelVersion: 'test', featureVersion: 'test', blendVersion: 'test');
    $artifact = ModelArtifact::query()->create(['training_run_id' => $trainingRun->id, 'sport' => 'mlb', 'market_type' => 'multi_market',
        'model_type' => 'mlb_tabular_bundle', 'model_version' => 'test', 'feature_version' => 'test', 'dataset_hash' => str_repeat('a', 64),
        'artifact_path' => 'test', 'artifact_hash' => str_repeat('b', 64), 'status' => 'promoted',
        'promoted_at' => now()->subHour(), 'promotion_decision' => ['promoted_markets' => ['win_probability', 'spread', 'total']]]);
    $output = ['home_win_probability' => 0.75, 'expected_home_margin' => 5.0, 'expected_total' => 9.5,
        'home_cover_probability' => 0.6, 'over_probability' => 0.6, 'uncertainty' => 0.1,
        'model_run_id' => $trainingRun->id, 'dataset_hash' => str_repeat('a', 64), 'feature_hash' => str_repeat('c', 64)];
    $game->update(['probable_home_pitcher_espn_id' => '100', 'probable_away_pitcher_espn_id' => '200']);
    $this->mock(MlbTabularModelInferenceService::class)->shouldReceive('predict')->once()->andReturn($output);
    $report = app(MlbChallengerShadowService::class)->run(artifactId: $artifact->id, gameId: $game->id);
    expect($report['outputs_created'])->toBe(3);
    $method = new ReflectionMethod(MlbChallengerShadowService::class, 'recordOutputs');
    expect($method->invoke(app(MlbChallengerShadowService::class), $artifact, $snapshot, $output))->toBe(0);
    expect(ShadowModelOutput::query()->where('canonical_prediction_id', $native->id)->count())->toBe(3)
        ->and(ShadowModelOutput::query()->whereNotNull('prediction_feature_snapshot_id')->count())->toBe(0);
    $odds = GameOddsSnapshot::query()->create(['sport' => 'mlb', 'game_table' => 'mlb_games', 'game_id' => $game->id,
        'source' => 'test', 'commence_time' => $game->sportEvent->starts_at, 'captured_at' => now()->subMinute(),
        'payload_hash' => str_repeat('1', 64), 'odds_data' => []]);
    MarketQuote::query()->create(['game_odds_snapshot_id' => $odds->id, 'sport' => 'mlb', 'game_table' => 'mlb_games',
        'game_id' => $game->id, 'source' => 'test', 'bookmaker_key' => 'test', 'market_key' => 'h2h', 'side' => 'home',
        'participant' => 'Home', 'price' => -110, 'implied_probability' => 0.5, 'no_vig_probability' => 0.5,
        'commence_time' => $game->sportEvent->starts_at, 'captured_at' => now()->subMinute(), 'is_pregame' => true, 'quote_hash' => str_repeat('2', 64)]);
    $this->artisan('sports:record-shadow-bet-decisions --sport=mlb')->assertSuccessful();
    expect(BetDecision::query()->count())->toBe(3);
    foreach (BetDecision::all() as $decision) {
        expect($decision->prediction_feature_snapshot_id)->toBeNull()
            ->and($decision->feature_snapshot['canonical_lineage']['calculation_run_id'])->toBe($native->calculation_run_id)
            ->and($decision->eligibility_reasons)->toContain('canonical_shadow_inputs_unvalidated')
            ->and($decision->is_bet)->toBeFalse();
    }
    expect(BetDecision::query()->where('market_type', 'moneyline')->sole()->eligibility_reasons)->toBe(['canonical_shadow_inputs_unvalidated']);
    $migration = require database_path('migrations/2026_10_28_195953_add_canonical_prediction_to_shadow_model_outputs.php');
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'Preserve native shadow lineage');
});

it('uses native NFL simulation sources and fits only the same canonical release', function () {
    [$target, $native] = pregameRetirementFixture('nfl');
    $release = $native->calculationRun->release;
    foreach ([10, 6] as $daysAgo) {
        $this->travelTo(now()->subDays($daysAgo));
        [$historical] = pregameRetirementFixture('nfl', $release);
        $this->travelTo('2026-10-28 12:00:00');
        $historical->update(['status' => 'STATUS_FINAL', 'home_score' => 10, 'away_score' => 7,
            'result_updated_at' => now()->subDays(2)]);
        $historical->update(['result_updated_at' => now()->subDays(2)]);
    }
    config(['nfl_game_simulation.minimum_history' => 2, 'nfl_game_simulation.capture_enabled' => true]);
    $service = app(GameSimulationService::class);
    $run = $service->preview($target, iterations: 1000, seed: 12);
    expect($run['status'])->toBe('shadow')->and($run['fitted'])->toBeTrue()
        ->and($run['features']['source_table'])->toBe('predictions')
        ->and($run['features']['history']['pairs'])->toHaveCount(2);
    $captured = $service->capture($target, $run);
    expect($captured->prediction_table)->toBe('predictions')
        ->and($captured->prediction_id)->toBe($native->id)
        ->and($captured->features['source_lineage']['calculation_run_id'])->toBe($native->calculation_run_id)
        ->and($captured->outputs['bet_eligible'])->toBeFalse();
    expect($service->preview($target, basis: 'pre_market')['reason'])->toBe('forecast_basis_unavailable');
});

it('checks canonical prediction coverage without requiring legacy rows', function () {
    [$game, $native] = pregameRetirementFixture('nba');
    $game->update(['season' => 2027]);
    $profile = config('validation.sports.nba');
    $profile['models']['game'] = $game::class;
    $check = app(PredictionCompletenessCheck::class);
    $result = $check->run('nba', $profile);
    expect($result['metadata']['active_games'])->toBe(1)
        ->and($result['metadata']['prediction_source'])->toBe('canonical')
        ->and($result['metadata']['games_missing_predictions'])->toBe(0);
    $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    expect($check->run('nba', $profile)['metadata']['games_missing_predictions'])->toBe(1);
});

it('runs native MLB period inference without creating a legacy prediction snapshot', function () {
    [$game, $native] = pregameRetirementFixture('mlb');
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

it('skips duplicate legacy morning schedules after cutover while preserving pipeline-only compatibility', function (string $sport) {
    config(["prediction_lifecycle.canonical_pipeline.{$sport}" => true, "prediction_lifecycle.canonical_reads.{$sport}" => true]);
    $events = collect(app(Schedule::class)->events());
    $legacy = $events->first(fn ($event) => str_contains($event->command ?? '', "{$sport}:generate-predictions")
        && ! str_contains($event->description ?? '', 'After Odds'));
    $native = $events->first(fn ($event) => str_contains($event->command ?? '', "{$sport}:generate-canonical-predictions"));
    expect($legacy)->not->toBeNull()->and($native)->not->toBeNull();
    expect($legacy->filtersPass(app()))->toBeFalse()->and($native->filtersPass(app()))->toBeTrue();
    config(["prediction_lifecycle.canonical_reads.{$sport}" => false]);
    expect($legacy->filtersPass(app()))->toBeTrue();
})->with(['nba', 'cbb', 'wcbb', 'wnba', 'mlb']);

it('regenerates native MLB forecasts when a probable pitcher changes', function () {
    [$game, $native] = pregameRetirementFixture('mlb');
    $game->update(['probable_home_pitcher_espn_id' => '100', 'probable_away_pitcher_espn_id' => '200']);
    $this->mock(SyncGamesFromScoreboard::class)->shouldReceive('execute')->twice()
        ->andReturnUsing(function () use ($game) {
            $game->update(['probable_home_pitcher_espn_id' => '300']);

            return 1;
        });
    $this->mock(GenerateCanonicalPrediction::class)->shouldReceive('execute')->once()
        ->withArgs(fn ($actual) => $actual->id === $game->id && $actual->probable_home_pitcher_espn_id === '300')->andReturn($native);
    $this->mock(GeneratePrediction::class)->shouldNotReceive('execute');
    $this->artisan('mlb:refresh-probable-pitchers --days-ahead=1')->expectsOutput('Regenerated predictions for 1 game(s).')->assertSuccessful();
    expect(PredictionFeatureSnapshot::query()->count())->toBe(0);
});

it('prevents queued legacy NFL shadow jobs and direct publishers from reviving active legacy writes', function () {
    [$game] = pregameRetirementFixture('nfl');
    $this->mock(NflFullHistoricalShadowInferenceService::class)->shouldNotReceive('evaluate');
    app()->call([new EvaluateShadowPrediction(123), 'handle']);
    expect(fn () => app(NflPredictionPublisher::class)->publish($game, [], []))
        ->toThrow(LogicException::class, 'Legacy pregame writes are retired');
});

it('uses selected native probabilities for CBB tournament outlook instead of conflicting legacy rows', function () {
    [$game] = pregameRetirementFixture('cbb');
    $game->update(['season_type' => 3, 'is_ncaa_tournament' => true, 'tournament_region' => 'East',
        'tournament_round' => 'round_of_64', 'home_seed' => 1, 'away_seed' => 16]);
    Prediction::factory()->create(['game_id' => $game->id, 'win_probability' => 0.2]);
    $snapshot = app(RecalculateTournamentOutlook::class)->execute(2026);
    $home = $snapshot->forecasts->firstWhere('team_id', $game->home_team_id);
    $away = $snapshot->forecasts->firstWhere('team_id', $game->away_team_id);
    expect((float) $home->round_of_32_probability)->toBe(0.7)
        ->and((float) $away->round_of_32_probability)->toBe(0.3);
});

it('preserves bounded NFL date ranges when the old command routes to native generation', function () {
    [$included, $native] = pregameRetirementFixture('nfl');
    [$excluded] = pregameRetirementFixture('nfl');
    $excluded->sportEvent->update(['starts_at' => now()->addDays(5)]);
    $excluded->update(['game_date' => now()->addDays(5)->toDateString()]);
    $this->mock(App\Actions\NFL\GenerateCanonicalPrediction::class)->shouldReceive('execute')->once()
        ->withArgs(fn ($actual) => $actual->id === $included->id)->andReturn($native);
    $this->artisan('nfl:generate-predictions', ['--season' => 2026, '--from-date' => now()->toDateString(),
        '--to-date' => now()->addDays(2)->toDateString()])->assertSuccessful();
    expect(PredictionFeatureSnapshot::query()->count())->toBe(0);
});
