<?php

use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\EventInputSnapshot;
use App\Models\ModelArtifact;
use App\Models\SportEvent;
use App\Services\CFB\Predictions\CfbCalculator;
use App\Services\CFB\Predictions\CfbFrozenMoneylineCalibrationDataset;
use App\Services\CFB\Predictions\CfbMoneylineTemporalCalibration;
use App\Services\Predictions\CanonicalPayloadHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function cfbFrozenCalibrationRows(): array
{
    $rows = [];
    foreach (['2026-09-01' => 100, '2026-09-08' => 50, '2026-09-15' => 50] as $day => $count) {
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['game_id' => count($rows) + 1, 'kickoff' => $day.'T12:00:00Z', 'pregame_safe' => true,
                'feature_model_win_probability' => 0.8, 'target_home_win' => $i % 2];
        }
    }

    return $rows;
}

it('fits frozen game-separated same-season holdouts without requiring four future seasons', function () {
    $trainer = new CfbMoneylineTemporalCalibration;
    $rows = cfbFrozenCalibrationRows();
    $model = $trainer->train($rows);
    expect($model['status'])->toBe('challenger')->and($model['counts'])->toBe(['train' => 100, 'validation' => 50, 'test' => 50])
        ->and($model['validation_passed'])->toBeTrue()->and($model['reports']['test']['brier_improvement_lower_95'])->toBeGreaterThan(0);
    foreach ($rows as &$row) {
        if (str_starts_with($row['kickoff'], '2026-09-15')) {
            $row['target_home_win'] = 1;
        }
    }
    unset($row);
    $changed = $trainer->train($rows);
    expect($changed['alpha'])->toBe($model['alpha'])->and($changed['beta'])->toBe($model['beta'])
        ->and($changed['validation_passed'])->toBeFalse();
});

it('rejects duplicated games unverified inputs invalid probabilities and empty evidence', function () {
    $trainer = new CfbMoneylineTemporalCalibration;
    expect($trainer->train([])['reason'])->toBe('minimum_unique_games_100_50_50');
    $rows = cfbFrozenCalibrationRows();
    expect($trainer->train([...$rows, $rows[0]])['reason'])->toBe('invalid_or_duplicate_game_evidence');
    $rows[0]['pregame_safe'] = false;
    expect($trainer->train($rows)['reason'])->toBe('invalid_or_duplicate_game_evidence');
    $rows[0]['pregame_safe'] = true;
    $rows[0]['feature_model_win_probability'] = NAN;
    expect($trainer->train($rows)['reason'])->toBe('invalid_or_duplicate_game_evidence');
});

it('does not split one UTC kickoff date between training validation and test', function () {
    $rows = cfbFrozenCalibrationRows();
    foreach ($rows as &$row) {
        $row['kickoff'] = $row['game_id'] % 2 ? '2026-09-01T23:00:00Z' : '2026-09-02T01:00:00+02:00';
    }
    unset($row);
    expect((new CfbMoneylineTemporalCalibration)->train($rows)['status'])->toBe('insufficient_evidence');
});

it('reassesses sparse frozen evidence without manufacturing a calibrated artifact', function () {
    $this->mock(CfbFrozenMoneylineCalibrationDataset::class)->shouldReceive('rows')->once()->with('1.6.2')
        ->andReturn(['rows' => [], 'configuration_hashes' => [], 'excluded' => []]);
    $this->artisan('cfb:train-frozen-moneyline-calibration', ['--release-version' => '1.6.2'])->assertSuccessful();
    expect(ModelArtifact::count())->toBe(0);
});

it('registers a passing frozen candidate for prospective shadow and reuses identical evidence', function () {
    Storage::fake('cfb-frozen-artifacts');
    Storage::fake('cfb-frozen-cache');
    config()->set('ml.storage.disk', 'cfb-frozen-artifacts');
    config()->set('ml.storage.cache_disk', 'cfb-frozen-cache');
    config()->set('filesystems.disks.cfb-frozen-artifacts.driver', 'local');
    $this->mock(CfbFrozenMoneylineCalibrationDataset::class)->shouldReceive('rows')->twice()->with('1.6.2')
        ->andReturn(['rows' => cfbFrozenCalibrationRows(), 'configuration_hashes' => ['one'], 'excluded' => []]);
    $this->artisan('cfb:train-frozen-moneyline-calibration', ['--release-version' => '1.6.2'])->assertSuccessful();
    $this->artisan('cfb:train-frozen-moneyline-calibration', ['--release-version' => '1.6.2'])->assertSuccessful();
    $artifact = ModelArtifact::sole();
    expect($artifact->status)->toBe('challenger')->and($artifact->metrics['validation_passed'])->toBeTrue()
        ->and($artifact->promoted_at)->toBeNull();
});

it('uses the last genuinely published pregame prediction once and rejects backdated records', function () {
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'season' => 2026, 'starts_at' => '2026-09-01 12:00:00']);
    $game = Game::factory()->create(['sport_event_id' => $event->id,
        'home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id,
        'status' => 'STATUS_FINAL', 'home_score' => 30, 'away_score' => 20]);
    $team = ['metrics' => ['wins' => 6, 'losses' => 0, 'points_per_game' => 28, 'points_allowed_per_game' => 21]];
    $snapshot = EventInputSnapshot::factory()->create(['sport_event_id' => $event->id, 'sport' => 'cfb',
        'captured_at' => '2026-09-01 10:00:00', 'cutoff_at' => $event->starts_at, 'latest_source_available_at' => '2026-09-01 09:00:00',
        'inputs' => ['home' => $team, 'away' => $team], 'created_at' => '2026-09-01 10:00:00']);
    $release = CalculationRelease::factory()->create(['sport' => 'cfb', 'semantic_version' => '1.6.2']);
    $revision = 0;
    foreach (['10:30:00' => 0.6, '11:30:00' => 0.7, '12:30:00' => 0.9] as $time => $p) {
        $run = CalculationRun::factory()->create(['sport_event_id' => $event->id,
            'event_input_snapshot_id' => $snapshot->id, 'calculation_release_id' => $release->id]);
        $prediction = CanonicalPrediction::factory()->create(['sport_event_id' => $event->id, 'sport' => 'cfb',
            'calculation_run_id' => $run->id, 'phase' => 'pregame', 'publication_state' => 'draft', 'revision' => ++$revision,
            'generated_at' => '2026-09-01 '.$time, 'published_at' => '2026-09-01 '.$time, 'created_at' => '2026-09-01 '.$time]);
        $prediction->markets()->create(['market_type' => 'moneyline', 'selection' => 'home', 'probability' => $p]);
        $prediction->update(['publication_state' => 'published']);
    }
    $data = (new CfbFrozenMoneylineCalibrationDataset)->rows('1.6.2');
    expect($data['rows'])->toHaveCount(1)->and($data['rows'][0]['feature_model_win_probability'])->toBe(0.7)
        ->and($data['rows'][0]['game_id'])->toBe($game->id)->and($data['rows'][0]['target_home_win'])->toBe(1);
    CalculationRelease::factory()->create(['sport' => 'cfb', 'semantic_version' => '1.6.3',
        'input_schema_version' => $snapshot->schema_version]);
    $output = new PredictionOutput([
        new PredictionMarketOutput('moneyline', 'home', probability: 0.7),
    ]);
    DB::table('predictions')->where('id', $data['rows'][0]['prediction_id'])->update([
        'output_hash' => app(CanonicalPayloadHasher::class)->hash($output->hashablePayload()),
    ]);
    $this->mock(CfbCalculator::class)->shouldReceive('calculate')->andReturn($output);
    $compatible = (new CfbFrozenMoneylineCalibrationDataset)->rows('1.6.3');
    expect($compatible['rows'])->toHaveCount(1)->and($compatible['rows'][0]['compatibility_basis'])->toBe('exact_frozen_output_replay');
    DB::table('predictions')->where('id', $data['rows'][0]['prediction_id'])->update(['output_hash' => str_repeat('a', 64)]);
    expect((new CfbFrozenMoneylineCalibrationDataset)->rows('1.6.3')['rows'])->toBe([]);
    DB::table('event_input_snapshots')->where('id', $snapshot->id)->update(['created_at' => '2026-09-02 10:00:00']);
    expect((new CfbFrozenMoneylineCalibrationDataset)->rows('1.6.2')['rows'])->toBe([]);
});
