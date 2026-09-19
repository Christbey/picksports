<?php

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\EventInputSnapshot;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\ModelArtifact;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\CFB\Predictions\CfbSpreadCalibrationDataset;
use App\Services\CFB\Predictions\CfbSpreadCoverProbabilityService;
use App\Services\CFB\Predictions\CfbSpreadResidualCalibration;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('settles integer spread pushes separately and computes EV from the actual American price', function () {
    $service = new CfbSpreadResidualCalibration;
    $p = $service->probabilities([-1, 0, 1, 2], 3, 'home', -3);
    expect($p)->toBe(['cover_probability' => 0.5, 'push_probability' => 0.25, 'loss_probability' => 0.25]);
    expect($service->price($p, -200)['expected_value_per_unit'])->toBe(0.0)
        ->and($service->price($p, 150)['expected_value_per_unit'])->toBe(0.5)
        ->and($service->price($p, -300)['positive_expected_value'])->toBeFalse();
    $away = $service->probabilities([-1, 0, 1, 2], 3, 'away', 3);
    expect($away['cover_probability'])->toBe($p['loss_probability'])->and($away['push_probability'])->toBe(0.25)
        ->and($service->probabilities([-1, 0, 1, 2], 3, 'home', -3.5)['push_probability'])->toBe(0.0);
});

it('does not invent cover probability or EV without a promoted validated artifact or executable price', function () {
    $service = new CfbSpreadCoverProbabilityService;
    $prediction = new CanonicalPrediction;
    $result = $service->assess($prediction, 'home', -3, -110);
    expect($result['status'])->toBe('unavailable')->and($result['cover_probability'])->toBeNull()
        ->and($result['expected_value_per_unit'])->toBeNull()->and($result['positive_expected_value'])->toBeFalse();
    expect($service->assess($prediction, 'home', -3, null)['risk_flags'])->toBe(['spread_price_unavailable'])
        ->and($service->assess($prediction, 'home', -3.25, -110)['risk_flags'])->toBe(['unsupported_spread_settlement']);
});

it('requires chronological complete-date holdouts and never trains on test-season results', function () {
    $trainer = new CfbSpreadResidualCalibration;
    expect($trainer->train([])['status'])->toBe('insufficient_evidence');
    $rows = [];
    foreach ([2023 => 500, 2024 => 200, 2025 => 200] as $season => $n) {
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['season' => $season, 'kickoff' => $season.'-09-01T12:00:00Z', 'pregame_safe' => true,
                'model_margin' => 3, 'actual_margin' => $i % 2 ? 2 : 4, 'home_line' => -3.5];
        }
    }
    $model = $trainer->train($rows);
    expect($model['status'])->toBe('challenger')->and($model['validation_passed'])->toBeTrue()
        ->and($model['counts'])->toBe(['train' => 500, 'validation' => 200, 'test' => 200]);
    foreach ($rows as &$row) {
        if ($row['season'] === 2025) {
            $row['actual_margin'] = 50;
        }
    }
    unset($row);
    $changed = $trainer->train($rows);
    expect($changed['residuals'])->toBe($model['residuals'])->and($changed['validation_passed'])->toBeFalse();
    $rows[0]['pregame_safe'] = false;
    expect($trainer->train($rows)['reason'])->toBe('unverified_pregame_rows');
});

it('reports unavailable evidence without registering an artifact', function () {
    $this->mock(CfbSpreadCalibrationDataset::class)->shouldReceive('rows')->once()->with('1.4.0')
        ->andReturn(['rows' => [], 'configuration_hashes' => [], 'excluded' => ['no_verified_contemporaneous_prediction' => 189]]);
    $this->artisan('cfb:train-spread-calibration', ['--release-version' => '1.4.0'])->assertSuccessful();
    expect(ModelArtifact::query()->count())->toBe(0);
});

it('accepts the last stored pregame quote before forecast publication while ignoring in-play lines', function () {
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'season' => 2026, 'starts_at' => '2026-09-01 12:00:00']);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026,
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'status' => 'STATUS_FINAL', 'home_score' => 30, 'away_score' => 20]);
    $team = ['elo_evidence' => ['qualified' => true], 'metrics' => ['wins' => 6, 'losses' => 0, 'points_per_game' => 28, 'points_allowed_per_game' => 21]];
    $snapshot = EventInputSnapshot::factory()->create(['sport_event_id' => $event->id, 'sport' => 'cfb',
        'captured_at' => '2026-09-01 10:00:00', 'cutoff_at' => $event->starts_at, 'latest_source_available_at' => '2026-09-01 09:00:00',
        'inputs' => ['require_versioned_elo' => true, 'home' => $team, 'away' => $team]]);
    $release = CalculationRelease::factory()->create(['sport' => 'cfb', 'semantic_version' => '1.4.0']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $snapshot->id,
        'calculation_release_id' => $release->id]);
    $prediction = CanonicalPrediction::factory()->create(['sport_event_id' => $event->id, 'sport' => 'cfb',
        'calculation_run_id' => $run->id, 'phase' => 'pregame', 'publication_state' => 'draft',
        'generated_at' => '2026-09-01 11:59:30', 'published_at' => '2026-09-01 11:59:30']);
    $prediction->markets()->create(['market_type' => 'spread', 'selection' => 'home', 'projected_line' => -7]);
    $prediction->update(['publication_state' => 'published']);
    $odds = GameOddsSnapshot::create(['sport' => 'cfb', 'game_table' => 'cfb_games', 'game_id' => $game->id,
        'source' => 'test', 'captured_at' => '2026-09-01 10:30:00', 'payload_hash' => hash('sha256', 'test'), 'odds_data' => []]);
    foreach (['10:30:00' => -7, '11:59:00' => -8, '12:01:00' => -20] as $time => $line) {
        $quote = MarketQuote::create(['game_odds_snapshot_id' => $odds->id, 'sport' => 'cfb', 'game_table' => 'cfb_games',
            'game_id' => $game->id, 'source' => 'test', 'bookmaker_key' => 'book', 'market_key' => 'spreads', 'side' => 'home',
            'line' => $line, 'created_at' => '2026-09-01 '.$time, 'captured_at' => '2026-09-01 '.$time, 'is_pregame' => true, 'quote_hash' => hash('sha256', $time)]);
        DB::table('market_quotes')->where('id', $quote->id)->update(['created_at' => '2026-09-01 '.$time]);
    }
    $rows = (new CfbSpreadCalibrationDataset)->rows('1.4.0');
    expect($rows['rows'])->toHaveCount(1)->and($rows['rows'][0]['home_line'])->toBe(-8.0)
        ->and($rows['rows'][0]['model_margin'])->toBe(7.0)->and($rows['rows'][0]['actual_margin'])->toBe(10.0);
    DB::table('event_input_snapshots')->where('id', $snapshot->id)
        ->update(['captured_at' => '2026-09-01 12:01:00']);
    expect((new CfbSpreadCalibrationDataset)->rows('1.4.0')['rows'])->toBe([]);
});

it('requires promoted shadow-reviewed release-compatible artifacts before exposing calibrated EV', function () {
    $release = CalculationRelease::factory()->make(['semantic_version' => '1.4.0', 'configuration_hash' => 'release-hash']);
    $run = new CalculationRun;
    $run->setRelation('release', $release);
    $prediction = new CanonicalPrediction(['generated_at' => '2026-09-01']);
    $prediction->setRelation('calculationRun', $run);
    $prediction->setRelation('markets', collect([new PredictionMarket(['market_type' => 'spread', 'selection' => 'home', 'projected_line' => -3])]));
    $training = app(ModelRunRecorder::class)->create('cfb', 'training', CfbSpreadResidualCalibration::VERSION, 'frozen-cfb-margin-v1', 'shadow-only');
    $artifact = ModelArtifact::create(['id' => (string) Str::uuid(), 'training_run_id' => $training->id,
        'sport' => 'cfb', 'market_type' => 'spread', 'model_type' => 'empirical_residual_distribution',
        'model_version' => CfbSpreadResidualCalibration::VERSION, 'feature_version' => 'frozen-cfb-margin-v1',
        'dataset_hash' => hash('sha256', 'test'), 'artifact_hash' => hash('sha256', 'test'), 'artifact_path' => '/unused',
        'status' => 'challenger', 'metrics' => ['validation_passed' => true], 'promotion_decision' => ['prospective_shadow_passed' => true]]);
    config()->set('cfb.predictions.spread_value.calibration_artifact_id', $artifact->id);
    $service = new CfbSpreadCoverProbabilityService;
    expect($service->assess($prediction, 'home', -3, -110)['status'])->toBe('unavailable');
    $artifact->update(['status' => 'promoted']);
    $path = tempnam(sys_get_temp_dir(), 'cfb-calibration-test-');
    $model = ['model_version' => CfbSpreadResidualCalibration::VERSION, 'release_version' => '1.4.0',
        'release_configuration_hash' => 'release-hash', 'validation_passed' => true,
        'evaluated_through' => '2025-12-01', 'trained_at' => '2026-01-01',
        'counts' => ['train' => 500, 'validation' => 200, 'test' => 200],
        'model_margin_range' => [-40, 40], 'validated_line_buckets' => ['under20'], 'residuals' => [-1, 0, 1, 2]];
    file_put_contents($path, json_encode($model));
    $this->mock(ModelArtifactRegistry::class)->shouldReceive('materializeArtifact')->andReturn($path);
    try {
        $result = $service->assess($prediction, 'home', -3, -110);
        expect($result['status'])->toBe('calibrated')->and($result['push_probability'])->toBe(0.25)
            ->and($result['positive_expected_value'])->toBeTrue();
        expect($service->assess($prediction, 'home', -35, -110)['risk_flags'])->toBe(['spread_calibration_line_bucket_unvalidated']);
        $model['release_configuration_hash'] = 'different-model';
        file_put_contents($path, json_encode($model));
        expect($service->assess($prediction, 'home', -3, -110)['risk_flags'])->toBe(['spread_calibration_incompatible']);
    } finally {
        unlink($path);
    }
});

it('allows enough same-season chronological evidence but never splits one kickoff date across folds', function () {
    $rows = [];
    foreach (['2026-09-01' => 500, '2026-09-08' => 200, '2026-09-15' => 200] as $day => $n) {
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['season' => 2026, 'kickoff' => $day.'T12:00:00Z', 'pregame_safe' => true,
                'model_margin' => 3, 'actual_margin' => $i % 2 ? 2 : 4, 'home_line' => -3.5];
        }
    }
    $trainer = new CfbSpreadResidualCalibration;
    $model = $trainer->train($rows);
    expect($model['status'])->toBe('challenger')->and($model['validated_line_buckets'])->toBe(['under20'])
        ->and($model['line_bucket_reports']['35plus']['validated'])->toBeFalse();
    foreach ($rows as &$row) {
        $row['kickoff'] = '2026-09-01T12:00:00Z';
    }
    unset($row);
    expect($trainer->train($rows)['status'])->toBe('insufficient_evidence');
});

it('registers sufficiently tested distributions only as challengers without activating them', function () {
    Storage::fake('cfb-spread-artifacts');
    Storage::fake('cfb-spread-cache');
    config()->set('ml.storage.disk', 'cfb-spread-artifacts');
    config()->set('ml.storage.cache_disk', 'cfb-spread-cache');
    config()->set('filesystems.disks.cfb-spread-artifacts.driver', 'local');
    $rows = [];
    foreach (['2026-09-01' => 500, '2026-09-08' => 200, '2026-09-15' => 200] as $day => $n) {
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['season' => 2026, 'kickoff' => $day.'T12:00:00Z', 'pregame_safe' => true,
                'model_margin' => 3, 'actual_margin' => $i % 2 ? 2 : 4, 'home_line' => -3.5];
        }
    }
    $this->mock(CfbSpreadCalibrationDataset::class)->shouldReceive('rows')->once()->with('1.4.0')
        ->andReturn(['rows' => $rows, 'configuration_hashes' => ['one-hash'], 'excluded' => []]);
    $this->artisan('cfb:train-spread-calibration', ['--release-version' => '1.4.0'])->assertSuccessful();
    $artifact = ModelArtifact::query()->sole();
    expect($artifact->status)->toBe('challenger')->and($artifact->market_type)->toBe('spread')
        ->and($artifact->metrics['validation_passed'])->toBeTrue()->and($artifact->promoted_at)->toBeNull();
});

it('counts historical coverage exclusions without fetching every game for a new release', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    foreach (range(1, 20) as $index) {
        $event = SportEvent::factory()->create(['sport' => 'cfb', 'season' => 2025, 'starts_at' => '2025-09-01']);
        Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => $home->id,
            'away_team_id' => $away->id, 'status' => 'STATUS_FINAL']);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $data = (new CfbSpreadCalibrationDataset)->rows('never-published-release');
        expect($data['rows'])->toBe([])->and($data['excluded']['no_verified_contemporaneous_prediction'])->toBe(20)
            ->and(count(DB::getQueryLog()))->toBeLessThanOrEqual(3);
    } finally {
        DB::disableQueryLog();
    }
});
