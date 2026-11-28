<?php

use App\Models\BetDecision;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\ModelRun;
use App\Models\PredictionFeatureSnapshot;
use App\Models\PredictionMarket;
use App\Models\ShadowModelOutput;
use App\Models\SportEvent;
use App\Services\MLB\MlbArtifactCohortSerializer;
use App\Services\MLB\MlbChallengerShadowService;
use App\Services\MLB\MlbTabularArtifactAdmission;
use App\Services\MLB\MlbTabularModelRunRegistrar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ObservedMlbDatasetFixture;
use Tests\Support\ObservedMlbGameFixture;
use Tests\Support\SingleActorSqliteMlbArtifactCohortSerializer;
use Tests\Support\SyntheticMlbTabularBundleFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Public source/registration protocol requires its own transaction. This is
    // functional SQLite coverage, not a range-lock or native concurrency claim.
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    app()->instance(MlbArtifactCohortSerializer::class, new SingleActorSqliteMlbArtifactCohortSerializer);
    Carbon::setTestNow('2026-10-28 12:00:00');
    CarbonImmutable::setTestNow('2026-10-28 12:00:00');
    Http::preventStrayRequests();
    Storage::fake('retire-object');
    Storage::fake('retire-cache');
    $this->retirementRoot = storage_path('framework/testing/r-'.Str::uuid());
    config(['mlb_ml.mode' => 'shadow', 'mlb_ml.shadow.enabled' => true, 'mlb_ml.period_models.enabled' => true,
        'ml.storage.disk' => 'retire-object', 'ml.storage.cache_disk' => 'retire-cache',
        'mlb_ml.bundle.staging_directory' => $this->retirementRoot.'/stage',
        'mlb_ml.bundle.extraction_directory' => $this->retirementRoot.'/extract', 'mlb_ml.bundle.input_directory' => $this->retirementRoot.'/input']);
});

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
    File::deleteDirectory($this->retirementRoot);
});

function retirementObservedMlbTarget(): array
{
    config(['prediction_lifecycle.canonical_pipeline.mlb' => true, 'prediction_lifecycle.canonical_reads.mlb' => true]);
    $event = SportEvent::factory()->create(['sport' => 'mlb', 'season' => 2026, 'starts_at' => now()->addDay()]);
    $game = Game::factory()->create(['season' => 2026, 'season_type' => '2', 'sport_event_id' => $event->id,
        'status' => 'STATUS_SCHEDULED', 'game_date' => $event->starts_at->toDateString(), 'game_time' => $event->starts_at->format('H:i:s'),
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        // Intended current target identity is present BEFORE the native capture.
        'probable_home_pitcher_espn_id' => '100', 'probable_away_pitcher_espn_id' => '200']);
    $game = ObservedMlbGameFixture::observe($game, $event->starts_at);
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'mlb', 'phase' => 'pregame']);
    $input = EventInputSnapshot::factory()->create(ObservedMlbGameFixture::snapshotAttributes($game, [
        'event' => ['starts_at' => $event->starts_at->toIso8601String()],
        'home' => ['team_id' => $game->home_team_id, 'elo' => 1550], 'away' => ['team_id' => $game->away_team_id, 'elo' => 1490],
        'pitching' => ['home' => ['elo' => 1600, 'espn_id' => '100'], 'away' => ['elo' => 1450, 'espn_id' => '200']],
    ]));
    $run = CalculationRun::factory()->create(['output_hash' => str_repeat('a', 64), 'sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['output_hash' => str_repeat('a', 64), 'sport' => 'mlb', 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'publication_state' => 'draft', 'phase' => 'pregame',
        'generated_at' => now(), 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ([['moneyline', 'home', null, 0.7], ['spread', 'home', -4.0, null], ['total', 'combined', 9.0, null]] as [$type, $side, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type,
            'selection' => $side, 'projected_line' => $line, 'probability' => $probability]);
    }
    $native->update(['publication_state' => 'published', 'published_at' => now()]);

    return [$game, $native->refresh(), $input];
}

function retirementQualifiedMlbArtifact($test): array
{
    $mutable = Carbon::getTestNow();
    $immutable = CarbonImmutable::getTestNow();
    Carbon::setTestNow('2025-06-27T06:00:00Z');
    CarbonImmutable::setTestNow('2025-06-27T06:00:00Z');
    try {
        $source = ObservedMlbDatasetFixture::create(retainDataset: true, gameDate: '2025-06-28', season: 2025,
            exportSeasons: [2025], finalizedAt: Carbon::parse('2025-06-30T06:00:00Z'));
    } finally {
        Carbon::setTestNow($mutable);
        CarbonImmutable::setTestNow($immutable);
    }
    try {
        $fixture = SyntheticMlbTabularBundleFixture::create($test->retirementRoot.'/run', $source['path']);
        $manifestPath = $fixture['run_directory'].'/manifest.json';
        $manifest = json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $manifest['training_seasons'] = [2025];
        File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
        $artifact = app(MlbTabularModelRunRegistrar::class)->register($fixture['run_directory'], $fixture['dataset_path']);
    } finally {
        @unlink($source['path']);
        @unlink($source['path'].'.manifest.json');
    }
    $runBefore = $artifact->trainingRun->fresh()->getRawOriginal();
    // Controlled synthetic market role, NOT scientific approval. Original
    // registered training bytes, evaluation and protected source proof are untouched.
    DB::table('model_artifacts')->where('id', $artifact->id)->update(['status' => 'promoted',
        'promoted_at' => now()->toDateTimeString(), 'promotion_decision' => json_encode(['promoted_markets' => ['win_probability', 'spread', 'total']], JSON_THROW_ON_ERROR)]);
    $artifact->refresh();
    expect(app(MlbTabularArtifactAdmission::class)->admit($artifact)['artifact']->id)->toBe($artifact->id)
        ->and($artifact->trainingRun->fresh()->getRawOriginal())->toBe($runBefore)
        ->and(data_get($artifact->trainingRun->parameters, 'training_seasons'))->toBe([2025]);

    return [$artifact, $source['game']];
}

function retirementFakeMlbCli(string $path): string
{
    File::put($path, <<<'CHILD'
<?php
$args = array_slice($argv, 1);
$runIndex = array_search('--run-dir', $args, true);
$inputIndex = array_search('--input', $args, true);
if (! is_int($runIndex) || ! is_int($inputIndex)) { exit(17); }
$manifest = json_decode(file_get_contents($args[$runIndex + 1].'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$features = json_decode(file_get_contents($args[$inputIndex + 1]), true, flags: JSON_THROW_ON_ERROR);
echo json_encode([['home_win_probability' => 0.75, 'expected_home_margin' => 5.0, 'expected_total' => 9.5,
    'home_cover_probability' => 0.6, 'over_probability' => 0.6, 'uncertainty' => 0.1,
    'model_run_id' => $manifest['model_run_id'], 'artifact_id' => $manifest['artifact_id'],
    'dataset_hash' => $manifest['dataset_hash'], 'feature_hash' => hash('sha256', json_encode($features))]], JSON_THROW_ON_ERROR);
CHILD);

    return $path;
}

it('records native shadow lineage idempotently and keeps unvalidated native shadow decisions on hold', function () {
    [$game, $native] = retirementObservedMlbTarget();
    [$artifact, $sourceGame] = retirementQualifiedMlbArtifact($this);
    expect($sourceGame->id)->not->toBe($game->id)->and((int) $sourceGame->season)->toBe(2025)->and((int) $game->season)->toBe(2026);
    config(['mlb_ml.process.command' => [PHP_BINARY, retirementFakeMlbCli($this->retirementRoot.'/fake.php')]]);
    $service = app(MlbChallengerShadowService::class);
    $report = $service->run(artifactId: $artifact->id, gameId: $game->id);
    expect($report['outputs_created'])->toBe(3);
    $outputIds = ShadowModelOutput::query()->orderBy('id')->pluck('id')->all();
    $successIds = ModelRun::query()->where('sport', 'mlb')->where('run_type', 'shadow_inference')->where('status', 'completed')->orderBy('id')->pluck('id')->all();
    $again = $service->run(artifactId: $artifact->id, gameId: $game->id);
    expect($again['outputs_created'])->toBe(0)
        ->and(ShadowModelOutput::query()->orderBy('id')->pluck('id')->all())->toBe($outputIds)
        ->and($successIds)->not->toBeEmpty()
        ->and(ModelRun::query()->where('sport', 'mlb')->where('run_type', 'shadow_inference')->where('status', 'completed')->orderBy('id')->pluck('id')->all())->toBe($successIds);
    expect(ShadowModelOutput::query()->where('canonical_prediction_id', $native->id)->count())->toBe(3)
        ->and(ShadowModelOutput::query()->whereNotNull('prediction_feature_snapshot_id')->count())->toBe(0)
        ->and(PredictionFeatureSnapshot::query()->where('sport', 'mlb')->where('game_id', $game->id)->count())->toBe(0);
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
