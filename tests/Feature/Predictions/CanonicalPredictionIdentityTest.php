<?php

use App\Models\CanonicalPrediction;
use App\Models\DatasetExportManifest;
use App\Models\FeatureSchema;
use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Models\NBA\Game as NbaGame;
use App\Models\NBA\Prediction;
use App\Models\NBA\Prediction as NbaPrediction;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Game as NflGame;
use App\Models\NFL\Prediction as NflPrediction;
use App\Models\NFL\Team as NflTeam;
use App\Models\PredictionFeatureSnapshot;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\Predictions\CanonicalPredictionLineageReadinessService;
use App\Services\Predictions\CanonicalPredictionSyncService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

function nbaLegacyPredictionWithEvent(array $predictionAttributes = []): NbaPrediction
{
    $event = SportEvent::factory()->create(['sport' => 'nba']);
    $game = NbaGame::factory()->create([
        'sport_event_id' => $event->getKey(),
        'home_team_id' => NbaTeam::factory(),
        'away_team_id' => NbaTeam::factory(),
    ]);

    return NbaPrediction::query()->create([
        'game_id' => $game->getKey(),
        'home_elo' => 1610,
        'away_elo' => 1540,
        'home_off_eff' => 118.4,
        'home_def_eff' => 109.1,
        'away_off_eff' => 112.0,
        'away_def_eff' => 111.9,
        'predicted_spread' => -6.5,
        'predicted_total' => 228.5,
        'win_probability' => 0.67,
        'confidence_score' => 74.2,
        'model_version' => 'nba-v3',
        'feature_version' => 'core-v2',
        ...$predictionAttributes,
    ]);
}

it('provides durable canonical prediction and market identities with explicit relationships', function () {
    $prediction = CanonicalPrediction::factory()->create();
    $market = PredictionMarket::factory()->for($prediction, 'prediction')->create();

    expect(Str::isUlid($prediction->public_id))->toBeTrue()
        ->and(Str::isUlid($market->public_id))->toBeTrue()
        ->and($prediction->getRouteKeyName())->toBe('public_id')
        ->and($market->getRouteKeyName())->toBe('public_id')
        ->and($prediction->sportEvent->predictions->first()->is($prediction))->toBeTrue()
        ->and($prediction->markets->first()->is($market))->toBeTrue()
        ->and($market->prediction->is($prediction))->toBeTrue();
});

it('allowlists canonical detail references instead of accepting client model class names', function () {
    $event = SportEvent::factory()->create(['sport' => 'nba']);

    expect(fn () => CanonicalPrediction::query()->create([
        'sport_event_id' => $event->getKey(),
        'sport' => 'nba',
        'detail_source' => Prediction::class,
        'detail_sport' => 'nba',
        'detail_id' => 1,
    ]))->toThrow(InvalidArgumentException::class, 'Unsupported canonical prediction detail source')
        ->and(fn () => PredictionMarket::factory()->make(['market_type' => 'arbitrary-client-market']))
        ->toThrow(InvalidArgumentException::class, 'Unsupported canonical prediction market type');
});

it('dual writes legacy predictions and markets idempotently', function () {
    $legacy = nbaLegacyPredictionWithEvent();
    $service = app(CanonicalPredictionSyncService::class);

    $first = $service->syncLegacyPrediction('nba', $legacy);
    $canonical = CanonicalPrediction::query()->sole();

    expect($first['predictions_created'])->toBe(1)
        ->and($first['markets_created'])->toBe(4)
        ->and($canonical->sport_event_id)->toBe($legacy->game->sport_event_id)
        ->and($canonical->detail_source)->toBe(CanonicalPrediction::DETAIL_SOURCE_LEGACY_SPORT_PREDICTION)
        ->and($canonical->detail_sport)->toBe('nba')
        ->and($canonical->detail_id)->toBe($legacy->getKey())
        ->and($canonical->markets()->where('market_type', 'moneyline')->where('selection', 'home')->value('probability'))->toBe('0.670000')
        ->and($canonical->markets()->where('market_type', 'moneyline')->where('selection', 'away')->value('probability'))->toBe('0.330000')
        ->and($canonical->markets()->where('market_type', 'spread')->value('projected_line'))->toBe('-6.5000')
        ->and($canonical->markets()->where('market_type', 'total')->value('projected_line'))->toBe('228.5000');

    $second = $service->syncLegacyPrediction('nba', $legacy->fresh());

    expect($second['already_synced'])->toBe(1)
        ->and(CanonicalPrediction::query()->count())->toBe(1)
        ->and(PredictionMarket::query()->count())->toBe(4);
});

it('updates the canonical market projection through the same dual write service', function () {
    $legacy = nbaLegacyPredictionWithEvent();
    $service = app(CanonicalPredictionSyncService::class);
    $service->syncLegacyPrediction('nba', $legacy);

    $legacy->update([
        'predicted_spread' => -4.25,
        'win_probability' => 0.61,
        'model_version' => 'nba-v4',
    ]);
    $result = $service->syncLegacyPrediction('nba', $legacy->fresh());
    $canonical = CanonicalPrediction::query()->sole();

    expect($result['predictions_updated'])->toBe(1)
        ->and($result['markets_updated'])->toBe(3)
        ->and($canonical->model_version)->toBe('nba-v4')
        ->and($canonical->markets()->where('market_type', 'spread')->value('projected_line'))->toBe('-4.3000')
        ->and($canonical->markets()->where('market_type', 'moneyline')->where('selection', 'home')->value('probability'))->toBe('0.610000');
});

it('supports write-free command dry runs for representative sport prediction tables', function () {
    $event = SportEvent::factory()->create(['sport' => 'nfl']);
    $game = NflGame::factory()->create([
        'sport_event_id' => $event->getKey(),
        'home_team_id' => NflTeam::factory(),
        'away_team_id' => NflTeam::factory(),
    ]);
    NflPrediction::factory()->create([
        'game_id' => $game->getKey(),
        'predicted_spread' => -3.5,
        'predicted_total' => 47.5,
        'win_probability' => 62,
        'confidence_score' => 71,
    ]);

    expect(Artisan::call('sports:backfill-canonical-predictions', [
        '--sport' => ['nfl'],
        '--dry-run' => true,
    ]))->toBe(0)
        ->and(Artisan::output())->toContain('Predictions that would be created')
        ->and(CanonicalPrediction::query()->count())->toBe(0)
        ->and(PredictionMarket::query()->count())->toBe(0);
});

it('leaves conflicting canonical references unchanged and reports the exact source conflict', function () {
    $legacy = nbaLegacyPredictionWithEvent();
    $otherEvent = SportEvent::factory()->create(['sport' => 'nba']);
    $canonical = CanonicalPrediction::factory()->create([
        'sport_event_id' => $otherEvent->getKey(),
        'sport' => 'nba',
        'detail_sport' => 'nba',
        'detail_id' => $legacy->getKey(),
    ]);

    $exitCode = Artisan::call('sports:backfill-canonical-predictions', [
        '--sport' => ['nba'],
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('canonical_identity_mismatch')
        ->and($output)->toContain((string) $legacy->getKey())
        ->and($canonical->fresh()->sport_event_id)->toBe($otherEvent->getKey())
        ->and(CanonicalPrediction::query()->count())->toBe(1);
});

it('reports predictions whose legacy game has not entered the canonical event bridge', function () {
    $game = NbaGame::factory()->create([
        'sport_event_id' => null,
        'home_team_id' => NbaTeam::factory(),
        'away_team_id' => NbaTeam::factory(),
    ]);
    NbaPrediction::factory()->create(['game_id' => $game->getKey()]);

    expect(Artisan::call('sports:backfill-canonical-predictions', [
        '--sport' => ['nba'],
    ]))->toBe(0)
        ->and(Artisan::output())->toContain('Missing canonical events')
        ->and(CanonicalPrediction::query()->count())->toBe(0);
});

it('links canonical predictions to exact deterministic lineage records', function () {
    $legacy = nbaLegacyPredictionWithEvent(['blend_version' => 'blend-v3']);
    $schemaHash = str_repeat('a', 64);
    $datasetHash = str_repeat('b', 64);
    $featureSchema = FeatureSchema::query()->create([
        'sport' => 'nba',
        'version' => 'core-v2',
        'schema_hash' => $schemaHash,
        'source' => 'prediction_feature_snapshot',
    ]);
    $dataset = DatasetExportManifest::factory()->create([
        'sport' => 'nba',
        'sha256' => $datasetHash,
        'schema_hash' => $schemaHash,
    ]);
    $run = ModelRun::query()->create([
        'id' => (string) Str::uuid(),
        'sport' => 'nba',
        'run_type' => 'prediction',
        'model_version' => 'nba-v3',
        'feature_version' => 'core-v2',
        'blend_version' => 'blend-v3',
        'config_hash' => str_repeat('c', 64),
        'parameters' => ['feature_schema_hash' => $schemaHash],
        'status' => 'completed',
        'started_at' => now(),
        'completed_at' => now(),
    ]);
    $artifact = ModelArtifact::query()->create([
        'id' => (string) Str::uuid(),
        'training_run_id' => $run->id,
        'sport' => 'nba',
        'market_type' => 'multi_market',
        'model_type' => 'test_bundle',
        'model_version' => 'nba-v3',
        'feature_version' => 'core-v2',
        'dataset_hash' => $datasetHash,
        'artifact_path' => '/private/test/model.json',
        'artifact_hash' => str_repeat('d', 64),
        'status' => 'promoted',
    ]);
    PredictionFeatureSnapshot::query()->create([
        'sport' => 'nba',
        'prediction_table' => $legacy->getTable(),
        'prediction_id' => $legacy->id,
        'game_id' => $legacy->game_id,
        'snapshot_run_id' => (string) Str::uuid(),
        'model_run_id' => $run->id,
        'model_version' => 'nba-v3',
        'feature_version' => 'core-v2',
        'blend_version' => 'blend-v3',
        'features' => [],
        'outputs' => [],
        'model_metadata' => ['model_artifact_id' => $artifact->id],
        'lineage_metadata' => [
            'feature_schema_hash' => $schemaHash,
            'dataset_hash' => $datasetHash,
        ],
        'feature_hash' => str_repeat('e', 64),
        'generated_at' => now(),
    ]);

    app(CanonicalPredictionSyncService::class)->syncLegacyPrediction('nba', $legacy);
    $canonical = CanonicalPrediction::query()->sole();

    expect($canonical->featureSchema->is($featureSchema))->toBeTrue()
        ->and($canonical->datasetExportManifest->is($dataset))->toBeTrue()
        ->and($canonical->modelRun->is($run))->toBeTrue()
        ->and($canonical->modelArtifact->is($artifact))->toBeTrue()
        ->and(Artisan::call('sports:report-canonical-prediction-lineage', [
            '--fail-on-incomplete' => true,
        ]))->toBe(0)
        ->and(Artisan::output())->toContain('100.00%');
});

it('reports missing lineage and never guesses between ambiguous exact-version candidates', function () {
    $legacy = nbaLegacyPredictionWithEvent(['blend_version' => 'blend-v3']);

    foreach ([str_repeat('1', 64), str_repeat('2', 64)] as $configHash) {
        ModelRun::query()->create([
            'id' => (string) Str::uuid(),
            'sport' => 'nba',
            'run_type' => 'prediction',
            'model_version' => 'nba-v3',
            'feature_version' => 'core-v2',
            'blend_version' => 'blend-v3',
            'config_hash' => $configHash,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    app(CanonicalPredictionSyncService::class)->syncLegacyPrediction('nba', $legacy);
    $canonical = CanonicalPrediction::query()->sole();
    $readiness = app(CanonicalPredictionLineageReadinessService::class)->report();

    expect($canonical->model_run_id)->toBeNull()
        ->and($canonical->feature_schema_id)->toBeNull()
        ->and($canonical->dataset_export_manifest_id)->toBeNull()
        ->and($canonical->model_artifact_id)->toBeNull()
        ->and(Artisan::call('sports:report-canonical-prediction-lineage', [
            '--json' => true,
            '--fail-on-incomplete' => true,
        ]))->toBe(1)
        ->and(Artisan::output())->toContain('"incomplete": 1')
        ->and($readiness['missing']['model_run'])->toBe(1);
});
