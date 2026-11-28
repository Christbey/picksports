<?php

use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Console\Commands\CFB\ExportScoringTrainingCommand;
use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\Play;
use App\Models\CFB\Team;
use App\Models\ModelRun;
use App\Models\SportEvent;
use App\Services\CFB\CfbPlayEpaWriter;
use App\Services\CFB\Data\CfbDataReadiness;
use App\Services\CFB\Data\CfbDriveBuilder;
use App\Services\CFB\Data\CfbGameDataValidator;
use App\Services\CFB\Data\CfbScoringLedger;
use App\Services\CFB\Data\CfbSourceAssessmentRecorder;
use App\Services\CFB\Data\CfbSourceRevision;
use App\Services\CFB\Scoring\CfbScoringChallenger;
use App\Services\CFB\Scoring\CfbTrainingSourceProvenance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function cfbUnknownAcceptedCheck(Game $game, string $component): GameDataCheck
{
    return GameDataCheck::create([
        'game_id' => $game->id, 'component' => $component, 'source_hash' => hash('sha256', 'unknown-'.$component),
        'validator_version' => CfbGameDataValidator::VERSION, 'source_path' => 'unknown-context.json',
        'state' => 'complete', 'discrepancies' => [], 'evidence' => [], 'accepted_at' => now(),
    ]);
}

it('does not treat pre-ledger accepted timestamps as verified current readiness', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'status' => 'STATUS_FINAL', 'home_score' => 0, 'away_score' => 0]);
    cfbUnknownAcceptedCheck($game, 'boxscore');
    cfbUnknownAcceptedCheck($game, 'plays');
    expect(app(CfbDataReadiness::class)->forGame($game)['ready'])->toBeFalse();
});

it('does not normalize drives using a pre-ledger accepted play timestamp', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'status' => 'STATUS_FINAL', 'home_score' => 0, 'away_score' => 0]);
    cfbUnknownAcceptedCheck($game, 'plays');
    expect(app(CfbDriveBuilder::class)->build($game)['state'])->toBe('blocked');
});

it('does not export accepted source identities without observed context receipts', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'status' => 'STATUS_FINAL', 'home_score' => 0, 'away_score' => 0]);
    cfbUnknownAcceptedCheck($game, 'boxscore');
    cfbUnknownAcceptedCheck($game, 'plays');
    $command = app(ExportScoringTrainingCommand::class);
    $method = new ReflectionMethod($command, 'acceptedChecks');
    expect($method->invoke($command, collect([$game]), CarbonImmutable::now()->addMinute()))->toBeEmpty();
});

require_once __DIR__.'/../../Support/CfbAssessmentFixtures.php';

it('fences EPA writes after unchanged source bytes are assessed in a corrected game context', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'status' => 'STATUS_FINAL', 'home_score' => 0, 'away_score' => 0]);
    Storage::fake('local');
    $source = app(CfbSourceRevision::class);
    $validation = ['state' => 'complete', 'discrepancies' => [], 'evidence' => ['synthetic_observation' => 1]];
    $check = $source->record($game, 'plays', ['synthetic' => 'same bytes'], $validation);
    $check->update(['accepted_at' => now()]);
    $recorder = app(CfbSourceAssessmentRecorder::class);
    $oldId = $recorder->acceptanceReceipt($check)->id;
    $play = Play::create(['game_id' => $game->id, 'espn_play_id' => 'synthetic-fence', 'source_revision' => $check->source_hash]);
    $rows = [['id' => $play->id, 'is_epa_eligible' => true, 'expected_points_before' => 1, 'expected_points_after' => 2, 'true_epa' => 1, 'epa_calculated_at' => now()]];
    $game->update(['home_score' => 1]);
    $check = $source->record($game->fresh(), 'plays', ['synthetic' => 'same bytes'], $validation);
    $check->update(['accepted_at' => now()]);
    $newId = $recorder->acceptanceReceipt($check)->id;
    expect($newId)->not->toBe($oldId);
    $writer = app(CfbPlayEpaWriter::class);
    expect(fn () => $writer->write($game->id, $check->source_hash, $rows, $oldId, null))->toThrow(DomainException::class);
    expect($play->fresh()->true_epa)->toBeNull();
    $writer->write($game->id, $check->source_hash, $rows, $newId, null);
    expect((float) $play->fresh()->true_epa)->toBe(1.0);
});

it('refuses drive projection writes when the game context changes during normalization', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'status' => 'STATUS_FINAL', 'home_score' => 0, 'away_score' => 0]);
    $check = cfbObservedAcceptedFixture(['game_id' => $game->id, 'component' => 'plays', 'source_hash' => hash('sha256', 'synthetic-drive-fence'), 'source_path' => 'synthetic.json', 'validator_version' => CfbGameDataValidator::VERSION, 'state' => 'complete', 'discrepancies' => [], 'evidence' => [], 'accepted_at' => now()]);
    DB::table('cfb_drives')->insert(['game_id' => $game->id, 'source_revision' => $check->source_hash, 'drive_key' => 'retained', 'offense_team_id' => $game->home_team_id, 'quality' => 'partial', 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $this->mock(CfbScoringLedger::class)->shouldReceive('normalize')->once()->andReturnUsing(function ($input, $plays) use ($game) {
        $game->fresh()->update(['home_score' => 1]);

        return $plays;
    });
    expect(fn () => app(CfbDriveBuilder::class)->build($game))->toThrow(DomainException::class);
    expect(DB::table('cfb_drives')->where('drive_key', 'retained')->count())->toBe(1);
});

it('rejects incomplete export training before a model run or process starts', function () {
    $path = tempnam(sys_get_temp_dir(), 'cfb-incomplete-manifest-');
    file_put_contents($path, json_encode(['scan' => ['complete' => false, 'has_more' => true, 'after_game_id' => 0]]));
    Process::fake();
    try {
        $this->artisan('cfb:train-scoring-challenger', ['--manifest' => $path, '--output' => $path.'.out'])->assertFailed();
        expect(ModelRun::query()->count())->toBe(0);
        Process::assertNothingRan();
    } finally {
        unlink($path);
    }
});

it('rejects same second training context correction and unknown artifact receipts without changing the baseline forecast', function () {
    $this->freezeTime();
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'status' => 'STATUS_FINAL', 'home_score' => 0, 'away_score' => 0]);
    foreach (['boxscore', 'plays'] as $component) {
        cfbObservedAcceptedFixture(['game_id' => $game->id, 'component' => $component, 'source_hash' => hash('sha256', 'artifact-'.$component), 'source_path' => 'synthetic.json', 'validator_version' => CfbGameDataValidator::VERSION, 'state' => 'complete', 'discrepancies' => [], 'evidence' => [], 'accepted_at' => now()]);
    }
    $artifact = ['training_ids' => [$game->id], 'source_assessment_provenance' => cfbSyntheticTrainingProvenance($game)];
    $gate = app(CfbTrainingSourceProvenance::class);
    expect($gate->rejection($artifact))->toBeNull()
        ->and($gate->rejection(['training_ids' => [$game->id]]))->toBe('unknown_training_source_provenance');
    $game->update(['home_score' => 1]);
    expect($gate->rejection($artifact))->toBe('training_source_assessment_changed');
    $game->update(['home_score' => 0]);
    cfbReobserveSyntheticChecks($game);
    expect($gate->rejection($artifact))->toBe('training_source_assessment_changed')
        ->and($gate->rejection(['training_ids' => [$game->id], 'source_assessment_provenance' => cfbSyntheticTrainingProvenance($game)]))->toBeNull();
    $baseline = new PredictionOutput([
        new PredictionMarketOutput('spread', 'home', projectedLine: -7),
    ]);
    $result = app(CfbScoringChallenger::class)->apply($baseline, ['state' => 'stale', 'reason' => 'training_source_assessment_changed']);
    expect($result->markets)->toBe($baseline->markets);
});

it('refuses a current receipt when the supplied calculation game context is stale', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'status' => 'STATUS_FINAL', 'home_score' => 0, 'away_score' => 0]);
    cfbObservedAcceptedFixture(['game_id' => $game->id, 'component' => 'plays', 'source_hash' => hash('sha256', 'stale-input-context'), 'source_path' => 'synthetic.json', 'validator_version' => CfbGameDataValidator::VERSION, 'state' => 'complete', 'discrepancies' => [], 'evidence' => [], 'accepted_at' => now()]);
    $current = $game->fresh();
    $current->update(['home_score' => 1]);
    cfbReobserveSyntheticChecks($current);
    expect(app(CfbDataReadiness::class)->acceptedCheck($game, 'plays'))->toBeNull()
        ->and(app(CfbDataReadiness::class)->acceptedCheck($current, 'plays'))->not->toBeNull();
});

it('rejects unbounded or invalid export scope before creating output', function (string $limit) {
    $path = sys_get_temp_dir().'/cfb-invalid-scope-'.Str::uuid().'.jsonl';
    $this->artisan('cfb:export-scoring-training', ['--from-season' => 2026, '--to-season' => 2026,
        '--as-of' => now()->toIso8601String(), '--max-games' => $limit, '--output' => $path])->assertFailed();
    expect(file_exists($path))->toBeFalse();
})->with(['0', '-1', '1001', 'invalid']);

it('fences EPA against a changed boxscore assessment even when its accepted play receipt is unchanged', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'status' => 'STATUS_FINAL', 'home_score' => 0, 'away_score' => 0]);
    foreach (['boxscore', 'plays'] as $component) {
        cfbObservedAcceptedFixture(['game_id' => $game->id, 'component' => $component, 'source_hash' => hash('sha256', 'epa-box-'.$component), 'source_path' => 'synthetic.json', 'validator_version' => CfbGameDataValidator::VERSION, 'state' => 'complete', 'discrepancies' => [], 'evidence' => [], 'accepted_at' => now()]);
    }
    $proof = cfbSyntheticTrainingProvenance($game)['games'][$game->id];
    $play = Play::create(['game_id' => $game->id, 'espn_play_id' => 'unchanged-play', 'source_revision' => $proof['plays']['source_hash']]);
    $rows = [['id' => $play->id, 'is_epa_eligible' => true, 'expected_points_before' => 1, 'expected_points_after' => 2, 'true_epa' => 1, 'epa_calculated_at' => now()]];
    cfbObservedAcceptedFixture(['game_id' => $game->id, 'component' => 'boxscore', 'source_hash' => hash('sha256', 'new-box'), 'source_path' => 'new.json', 'validator_version' => CfbGameDataValidator::VERSION, 'state' => 'complete', 'discrepancies' => [], 'evidence' => [], 'accepted_at' => now()]);
    expect(fn () => app(CfbPlayEpaWriter::class)->write($game->id, $proof['plays']['source_hash'], $rows, $proof['plays']['receipt_id'], $proof['boxscore']['receipt_id']))->toThrow(DomainException::class);
    expect($play->fresh()->true_epa)->toBeNull();
});

it('invalidates frozen training evidence when timing or neutral venue facts change without a new source receipt', function (string $field, mixed $value) {
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'status' => 'STATUS_FINAL', 'home_score' => 0, 'away_score' => 0, 'neutral_site' => false, 'season_type' => 'regular']);
    foreach (['boxscore', 'plays'] as $component) {
        cfbObservedAcceptedFixture(['game_id' => $game->id, 'component' => $component, 'source_hash' => hash('sha256', 'feature-fence-'.$component), 'source_path' => 'synthetic.json', 'validator_version' => CfbGameDataValidator::VERSION, 'state' => 'complete', 'discrepancies' => [], 'evidence' => [], 'accepted_at' => now()]);
    }
    $artifact = ['training_ids' => [$game->id], 'source_assessment_provenance' => cfbSyntheticTrainingProvenance($game)];
    $gate = app(CfbTrainingSourceProvenance::class);
    expect($gate->rejection($artifact))->toBeNull();
    $game->update([$field => $value]);
    expect($gate->rejection($artifact))->not->toBeNull();
})->with([
    'kickoff date' => ['game_date', '2028-01-01 00:00:00'],
    'neutral site' => ['neutral_site', true],
    'season type' => ['season_type', 'postseason'],
    'venue' => ['venue_name', 'Corrected synthetic stadium'],
]);

it('fences changed canonical kickoff while retaining the exact stored training source receipts', function () {
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->subDay()]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    foreach (['boxscore', 'plays'] as $component) {
        cfbObservedAcceptedFixture(['game_id' => $game->id, 'component' => $component, 'source_hash' => hash('sha256', 'canonical-time-'.$component), 'source_path' => 'synthetic.json', 'validator_version' => CfbGameDataValidator::VERSION, 'state' => 'complete', 'discrepancies' => [], 'evidence' => [], 'accepted_at' => now()]);
    }
    $proof = cfbSyntheticTrainingProvenance($game);
    $artifact = ['training_ids' => [$game->id], 'source_assessment_provenance' => $proof];
    $gate = app(CfbTrainingSourceProvenance::class);
    expect($gate->rejection($artifact))->toBeNull();
    $unknown = $artifact;
    unset($unknown['source_assessment_provenance']['games'][$game->id]['plays']['game_feature_hash']);
    expect($gate->rejection($unknown))->toBe('unknown_training_source_provenance');
    $event->update(['starts_at' => now()->addDay()]);
    expect($gate->rejection($artifact))->toBe('training_game_features_changed');
});
