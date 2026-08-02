<?php

use App\Models\BetDecision;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\ModelArtifact;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Services\MLB\MlbPeriodFeatureBuilder;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

it('builds f3 and f5 training rows only from games completed earlier in time', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $first = Game::factory()->create([
        'season' => 2025,
        'season_type' => config('mlb.season.types.regular'),
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => '2025-04-01',
        'game_time' => '18:00:00',
        'status' => 'STATUS_FINAL',
        'home_score' => 4,
        'away_score' => 2,
        'home_linescores' => [1, 0, 1, 0, 0, 1, 0, 1, 0],
        'away_linescores' => [0, 0, 0, 1, 0, 0, 1, 0, 0],
    ]);
    $second = Game::factory()->create([
        'season' => 2025,
        'season_type' => config('mlb.season.types.regular'),
        'home_team_id' => $away->id,
        'away_team_id' => $home->id,
        'game_date' => '2025-04-02',
        'game_time' => '18:00:00',
        'status' => 'STATUS_FINAL',
        'home_score' => 1,
        'away_score' => 3,
        'home_linescores' => [0, 0, 0, 0, 0, 1, 0, 0, 0],
        'away_linescores' => [1, 0, 0, 1, 0, 0, 0, 1, 0],
    ]);

    $rows = app(MlbPeriodFeatureBuilder::class)->historicalRows([2025]);
    $firstF3 = $rows->first(fn (array $row): bool => $row['game_id'] === $first->id
        && $row['market_type'] === 'first_3_moneyline');
    $secondF3 = $rows->first(fn (array $row): bool => $row['game_id'] === $second->id
        && $row['market_type'] === 'first_3_moneyline');

    expect($rows)->toHaveCount(4)
        ->and($firstF3['feature_home_prior_games'])->toBe(0)
        ->and($firstF3['feature_away_prior_games'])->toBe(0)
        ->and($firstF3['target_class'])->toBe(2)
        ->and($secondF3['feature_home_prior_games'])->toBe(1)
        ->and($secondF3['feature_away_prior_games'])->toBe(1)
        ->and($secondF3['feature_home_period_elo'])->toBeLessThan(1500)
        ->and($secondF3['feature_away_period_elo'])->toBeGreaterThan(1500);
});

it('prices period moneylines with tie pushes and settles from inning scores', function () {
    Carbon::setTestNow('2026-07-30 12:00:00');
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $gameStart = Carbon::parse('2026-07-30 19:00:00');
    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => $gameStart->toDateString(),
        'game_time' => $gameStart->format('H:i:s'),
        'status' => 'STATUS_FINAL',
        'home_score' => 5,
        'away_score' => 4,
        'home_linescores' => [1, 0, 0, 2, 0, 0, 1, 1, 0],
        'away_linescores' => [0, 1, 0, 0, 2, 0, 1, 0, 0],
    ]);
    $trainingRun = app(ModelRunRecorder::class)->create(
        sport: 'mlb',
        runType: 'training',
        modelVersion: 'mlb-period-multiclass-v1',
        featureVersion: 'mlb-period-moneyline-v1',
        blendVersion: 'mlb-period-multiclass-v1',
        status: 'completed',
        completedAt: now()->subDay(),
    );
    $artifact = ModelArtifact::query()->create([
        'id' => (string) Str::uuid(),
        'training_run_id' => $trainingRun->id,
        'sport' => 'mlb',
        'market_type' => 'multi_market',
        'model_type' => 'mlb_period_bundle',
        'model_version' => 'mlb-period-multiclass-v1',
        'feature_version' => 'mlb-period-moneyline-v1',
        'dataset_hash' => str_repeat('a', 64),
        'artifact_path' => storage_path('app/ml/models/test-period.joblib'),
        'artifact_hash' => str_repeat('b', 64),
        'status' => 'promoted',
        'promotion_decision' => [
            'promoted_markets' => ['first_3_moneyline'],
        ],
        'promoted_at' => now()->subHour(),
    ]);
    $inferenceRun = app(ModelRunRecorder::class)->create(
        sport: 'mlb',
        runType: 'shadow_inference',
        modelVersion: $artifact->model_version,
        featureVersion: $artifact->feature_version,
        blendVersion: 'mlb-period-shadow-v1',
        status: 'completed',
        completedAt: now(),
    );
    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'home_team_elo' => 1500,
        'away_team_elo' => 1500,
        'home_pitcher_elo' => 1500,
        'away_pitcher_elo' => 1500,
        'home_combined_elo' => 1500,
        'away_combined_elo' => 1500,
        'predicted_spread' => 0,
        'predicted_total' => 8,
        'win_probability' => 0.5,
        'confidence_score' => 0,
    ]);
    $snapshot = PredictionFeatureSnapshot::query()->create([
        'sport' => 'mlb',
        'prediction_table' => 'mlb_predictions',
        'prediction_id' => $prediction->id,
        'game_id' => $game->id,
        'model_run_id' => $inferenceRun->id,
        'model_version' => 'mlb-rules-v1',
        'feature_version' => 'core-v1',
        'blend_version' => 'baseline-v1',
        'features' => [],
        'outputs' => [],
        'feature_hash' => str_repeat('c', 64),
        'generated_at' => now(),
        'game_start_at' => $gameStart,
        'features_available_at' => now()->subMinute(),
        'pregame_safe' => true,
        'availability_status' => 'observed_pregame',
    ]);
    $shadow = ShadowModelOutput::query()->create([
        'inference_run_id' => $inferenceRun->id,
        'model_artifact_id' => $artifact->id,
        'prediction_feature_snapshot_id' => $snapshot->id,
        'sport' => 'mlb',
        'game_table' => 'mlb_games',
        'game_id' => $game->id,
        'prediction_table' => 'mlb_predictions',
        'prediction_id' => $prediction->id,
        'market_type' => 'first_3_moneyline',
        'baseline_output' => 0.5,
        'challenger_output' => 0.375,
        'output_delta' => -0.125,
        'status' => 'promoted_shadow',
        'explanation' => [
            'multi_market_contract' => true,
            'period_moneyline_contract' => true,
            'market_promotion' => ['first_3_moneyline' => true],
            'challenger_outputs' => [
                'home_win_probability' => 0.3,
                'away_win_probability' => 0.5,
                'tie_probability' => 0.2,
                'conditional_home_win_probability' => 0.375,
                'conditional_away_win_probability' => 0.625,
                'uncertainty' => 0.8,
            ],
        ],
        'generated_at' => now(),
    ]);
    $oddsSnapshot = GameOddsSnapshot::query()->create([
        'sport' => 'mlb',
        'game_table' => 'mlb_games',
        'game_id' => $game->id,
        'source' => 'odds_api',
        'commence_time' => $gameStart,
        'captured_at' => now()->subMinutes(5),
        'payload_hash' => str_repeat('d', 64),
        'odds_data' => [],
    ]);
    MarketQuote::query()->create([
        'game_odds_snapshot_id' => $oddsSnapshot->id,
        'sport' => 'mlb',
        'game_table' => 'mlb_games',
        'game_id' => $game->id,
        'source' => 'odds_api',
        'bookmaker_key' => 'testbook',
        'market_key' => 'h2h_1st_3_innings',
        'side' => 'away',
        'price' => 110,
        'implied_probability' => 0.47619,
        'no_vig_probability' => 0.48,
        'commence_time' => $gameStart,
        'captured_at' => now()->subMinutes(5),
        'is_pregame' => true,
        'quote_hash' => str_repeat('e', 64),
    ]);

    $this->artisan('sports:record-shadow-bet-decisions', [
        '--sport' => 'mlb',
        '--artifact' => $artifact->id,
    ])->assertSuccessful();

    $decision = BetDecision::query()->where('shadow_model_output_id', $shadow->id)->firstOrFail();
    expect($decision->market_type)->toBe('first_3_moneyline')
        ->and($decision->market_key)->toBe('h2h_1st_3_innings')
        ->and($decision->side)->toBe('away')
        ->and($decision->is_public)->toBeFalse()
        ->and($decision->is_tracking_only)->toBeTrue()
        ->and($decision->is_bet)->toBeTrue()
        ->and((float) $decision->projected_value)->toEqualWithDelta(0.25, 0.0001)
        ->and((float) data_get($decision->explanation, 'tie_push_probability'))
        ->toEqualWithDelta(0.2, 0.0001);

    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'mlb'])
        ->assertSuccessful();
    $decision->refresh()->load('settlement');

    expect($decision->settlement->result_status)->toBe('push')
        ->and((float) $decision->settlement->profit_units)->toBe(0.0)
        ->and(data_get($decision->settlement->metadata, 'period_innings'))->toBe(3);

    Artisan::call('mlb:report-period-model-performance', [
        '--artifact' => $artifact->id,
        '--json' => true,
    ]);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect(data_get($report, 'first_3_moneyline.graded_predictions'))->toBe(1)
        ->and((float) data_get($report, 'first_3_moneyline.tie_rate'))->toBe(1.0)
        ->and((float) data_get($report, 'first_3_moneyline.counterfactual_roi'))->toBe(0.0);
});
