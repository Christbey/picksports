<?php

use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Models\MarketQuote;
use App\Models\MLB\Game;
use App\Models\MLB\PickCandidate;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\ModelArtifact;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Models\User;
use App\Services\MLB\Odds\MlbInningOddsSyncService;
use App\Services\MLB\Picks\MlbPickGradingService;
use App\Services\OddsApi\GameOddsSnapshotRecorder;
use App\Services\OddsApi\OddsApiService;
use App\Services\Predictions\ModelRunRecorder;
use App\Support\SportsViewCache;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery as m;

uses()->group('mlb', 'odds', 'signals');

afterEach(function (): void {
    Carbon::setTestNow();
    m::close();
});

it('exposes quote-independent F3 and F5 insights on the predictions API', function (): void {
    Carbon::setTestNow('2026-07-30 10:00:00');
    [$game, $home, $away] = inningSignalGame(now()->addDay());
    Game::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'game_date' => '2026-07-29',
        'game_time' => '19:10:00',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 4,
        'away_score' => 1,
        'home_linescores' => [1, 0, 0, 1, 0, 1, 0, 1, 0],
        'away_linescores' => [0, 0, 0, 0, 0, 1, 0, 0, 0],
    ]);
    $prediction = inningSignalPrediction($game);

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v2/sports/mlb/predictions?season=2026&from_date=2026-07-31&to_date=2026-07-31')
        ->assertOk()
        ->assertJsonPath('data.0.id', $prediction->id)
        ->assertJsonPath('data.0.period_insights.0.market_type', 'first_3_moneyline')
        ->assertJsonPath('data.0.period_insights.0.state', 'insight_only_no_market')
        ->assertJsonPath('data.0.period_insights.0.market_available', false)
        ->assertJsonPath('data.0.period_insights.0.home.record.wins', 1)
        ->assertJsonPath('data.0.period_insights.0.away.record.losses', 1)
        ->assertJsonPath('data.0.period_insights.0.risk_flags.0', 'limited_period_sample')
        ->assertJsonPath('data.0.period_insights.1.market_type', 'first_5_moneyline')
        ->assertJsonPath('data.0.period_insights.1.state', 'insight_only_no_market');
});

it('syncs event-level MLB inning markets into odds snapshots and normalized quotes', function (): void {
    Carbon::setTestNow('2026-07-30 08:00:00');
    [$game, $home, $away] = inningSignalGame(now()->addDay());
    $game->update([
        'odds_api_event_id' => 'mlb-event-1',
        'odds_data' => inningOddsPayload($home, $away, includeCore: true, includeInnings: false),
        'odds_updated_at' => now(),
    ]);
    $event = [
        'id' => 'mlb-event-1',
        'sport_key' => 'baseball_mlb',
        'commence_time' => '2026-07-31T19:10:00-05:00',
        'home_team' => $home->display_name,
        'away_team' => $away->display_name,
        'bookmakers' => inningOddsPayload($home, $away, includeCore: false, includeInnings: true)['bookmakers'],
    ];

    $odds = m::mock(OddsApiService::class)->makePartial();
    $odds->shouldReceive('getEventOdds')
        ->once()
        ->with('baseball_mlb', 'mlb-event-1', [
            'totals_1st_1_innings',
            'h2h_1st_3_innings',
            'h2h_1st_5_innings',
        ], 'draftkings')
        ->andReturn($event);

    $report = (new MlbInningOddsSyncService(
        $odds,
        app(GameOddsSnapshotRecorder::class),
        app(SportsViewCache::class),
    ))->sync();

    $game->refresh();
    $marketKeys = collect(data_get($game->odds_data, 'bookmakers.0.markets', []))->pluck('key');

    expect($report['updated'])->toBe(1)
        ->and($report['market_rows'])->toBe(3)
        ->and($marketKeys)->toContain(
            'h2h',
            'totals_1st_1_innings',
            'h2h_1st_3_innings',
            'h2h_1st_5_innings',
        )
        ->and(MarketQuote::query()->where('market_key', 'totals_1st_1_innings')->count())->toBe(2)
        ->and(MarketQuote::query()->where('market_key', 'h2h_1st_3_innings')->count())->toBe(2)
        ->and((float) MarketQuote::query()
            ->where('market_key', 'h2h_1st_5_innings')
            ->where('side', 'home')
            ->value('no_vig_probability'))->toBeGreaterThan(0.0)
        ->and((float) MarketQuote::query()
            ->where('market_key', 'h2h_1st_5_innings')
            ->where('side', 'home')
            ->value('no_vig_probability'))->toBeLessThan(1.0);
});

it('generates and grades first-inning F3 and F5 candidates from inning linescores', function (): void {
    Carbon::setTestNow('2026-07-30 10:00:00');
    [$game, $home, $away] = inningSignalGame(now()->startOfDay());
    $game->update([
        'game_time' => '19:10:00',
        'odds_api_event_id' => 'mlb-event-2',
        'odds_data' => inningOddsPayload($home, $away),
        'odds_updated_at' => now(),
    ]);
    inningSignalPrediction($game);

    $this->artisan('mlb:generate-daily-picks', [
        '--date' => '2026-07-30',
        '--season' => 2026,
        '--markets' => 'first_inning,first_3,first_5',
    ])->assertSuccessful();

    $candidates = PickCandidate::query()->get();

    expect($candidates)->toHaveCount(6)
        ->and($candidates->pluck('market_type')->unique()->values()->all())->toEqualCanonicalizing([
            'first_inning_total',
            'first_3_moneyline',
            'first_5_moneyline',
        ])
        ->and($candidates->every(fn (PickCandidate $candidate): bool => $candidate->game_start_at?->format('H:i:s') === '19:10:00'))->toBeTrue()
        ->and($candidates->every(fn (PickCandidate $candidate): bool => $candidate->is_tracking_only))->toBeTrue();

    Carbon::setTestNow('2026-07-30 23:00:00');
    $game->update([
        'status' => 'STATUS_FINAL',
        'home_score' => 3,
        'away_score' => 1,
        'home_linescores' => json_encode(['1', '0', '1', '0', '0', '1']),
        'away_linescores' => json_encode(['0', '0', '0', '0', '0', '1']),
    ]);

    $report = app(MlbPickGradingService::class)->gradeWithReport(2026);

    expect($report['graded'])->toBe(6)
        ->and($report['excluded'])->toBe(0)
        ->and(PickCandidate::query()->where('market_type', 'first_inning_total')->where('side', 'over')->value('result_status'))->toBe('win')
        ->and(PickCandidate::query()->where('market_type', 'first_inning_total')->where('side', 'under')->value('result_status'))->toBe('loss')
        ->and(PickCandidate::query()->where('market_type', 'first_3_moneyline')->where('side', 'home')->value('result_status'))->toBe('win')
        ->and(PickCandidate::query()->where('market_type', 'first_5_moneyline')->where('side', 'away')->value('result_status'))->toBe('loss')
        ->and(BetSettlement::query()->count())->toBe(6);
});

it('uses promoted period probabilities and exposes shadow lineage on the predictions board', function (): void {
    Carbon::setTestNow('2026-07-30 10:00:00');
    [$game, $home, $away] = inningSignalGame(now()->startOfDay());
    $game->update([
        'odds_data' => inningOddsPayload($home, $away),
        'odds_updated_at' => now(),
    ]);
    $prediction = inningSignalPrediction($game);
    $trainingRun = app(ModelRunRecorder::class)->create(
        sport: 'mlb',
        runType: 'training',
        modelVersion: 'mlb-period-multiclass-v1',
        featureVersion: 'mlb-period-moneyline-v1',
        blendVersion: 'mlb-period-multiclass-v1',
        metadata: ['python_model_run_id' => 'period-python-run'],
        status: 'completed',
        completedAt: now()->subDay(),
        configHash: str_repeat('1', 64),
    );
    $artifact = ModelArtifact::query()->create([
        'id' => (string) Str::uuid(),
        'training_run_id' => $trainingRun->id,
        'sport' => 'mlb',
        'market_type' => 'multi_market',
        'model_type' => 'mlb_period_bundle',
        'model_version' => 'mlb-period-multiclass-v1',
        'feature_version' => 'mlb-period-moneyline-v1',
        'dataset_hash' => str_repeat('2', 64),
        'artifact_path' => storage_path('app/ml/models/test-period.joblib'),
        'artifact_hash' => str_repeat('3', 64),
        'status' => 'promoted',
        'promotion_decision' => ['promoted_markets' => ['first_3_moneyline']],
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
    $gameStart = now()->setTime(19, 10);
    $snapshot = PredictionFeatureSnapshot::query()->create([
        'sport' => 'mlb',
        'prediction_table' => 'mlb_predictions',
        'prediction_id' => $prediction->id,
        'game_id' => $game->id,
        'snapshot_run_id' => (string) Str::uuid(),
        'model_run_id' => $inferenceRun->id,
        'model_version' => 'mlb-rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
        'features' => [],
        'outputs' => [],
        'feature_hash' => str_repeat('4', 64),
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
        'challenger_output' => 0.65,
        'output_delta' => 0.15,
        'status' => 'promoted_shadow',
        'explanation' => [
            'model_run_id' => 'period-python-run',
            'dataset_hash' => str_repeat('2', 64),
            'feature_hash' => str_repeat('4', 64),
            'active_source' => 'baseline',
            'apply_to_live_output' => false,
            'challenger_outputs' => [
                'home_win_probability' => 0.52,
                'away_win_probability' => 0.28,
                'tie_probability' => 0.20,
                'conditional_home_win_probability' => 0.65,
                'conditional_away_win_probability' => 0.35,
                'fair_home_price' => -186,
                'fair_away_price' => 186,
                'uncertainty' => 0.18,
                'model_name' => 'xgboost',
                'calibration_method' => 'isotonic',
            ],
        ],
        'generated_at' => now(),
    ]);
    BetDecision::query()->create([
        'decision_run_id' => (string) Str::uuid(),
        'model_run_id' => $inferenceRun->id,
        'model_artifact_id' => $artifact->id,
        'shadow_model_output_id' => $shadow->id,
        'prediction_feature_snapshot_id' => $snapshot->id,
        'source_table' => 'shadow_model_outputs',
        'source_id' => $shadow->id,
        'sport' => 'mlb',
        'game_table' => 'mlb_games',
        'game_id' => $game->id,
        'prediction_table' => 'mlb_predictions',
        'prediction_id' => $prediction->id,
        'market_type' => 'first_3_moneyline',
        'market_key' => 'h2h_1st_3_innings',
        'side' => 'home',
        'model_probability' => 0.65,
        'status' => 'shadow_no_bet',
        'recommendation_label' => 'no_bet',
        'is_public' => false,
        'is_tracking_only' => true,
        'is_bet' => false,
        'pregame_safe' => false,
        'eligibility_reasons' => ['pregame_market_quote_missing'],
        'reason_codes' => ['shadow_model_observation'],
        'risk_flags' => [],
        'decided_at' => now(),
        'locked_at' => now(),
        'game_start_at' => $gameStart,
        'decision_hash' => str_repeat('5', 64),
    ]);

    $this->artisan('mlb:generate-daily-picks', [
        '--date' => '2026-07-30',
        '--season' => 2026,
        '--markets' => 'first_3',
    ])->assertSuccessful();

    $homeCandidate = PickCandidate::query()
        ->where('market_type', 'first_3_moneyline')
        ->where('side', 'home')
        ->firstOrFail();
    expect((float) $homeCandidate->model_probability)->toEqualWithDelta(0.65, 0.0001)
        ->and(data_get($homeCandidate->feature_snapshot, 'model_source'))->toBe('promoted_period_model')
        ->and(data_get($homeCandidate->feature_snapshot, 'period_model_artifact_id'))->toBe($artifact->id);

    Sanctum::actingAs(User::factory()->create());
    $daily = $this->getJson('/api/v2/sports/mlb/daily-picks?date=2026-07-30&season=2026')
        ->assertOk()
        ->assertJsonPath('data.summary.first_3_priced_games', 1);
    $candidatePayload = collect($daily->json('data.candidates'))
        ->firstWhere('id', $homeCandidate->id);
    expect(data_get($candidatePayload, 'model_source'))->toBe('promoted_period_model')
        ->and(data_get($candidatePayload, 'period_models.0.lineage.artifact_id'))->toBe($artifact->id)
        ->and(data_get($candidatePayload, 'period_models.0.decision.status'))->toBe('shadow_no_bet')
        ->and(data_get($candidatePayload, 'period_models.0.decision.eligibility_reasons'))->toContain('pregame_market_quote_missing');

    expect(data_get($daily->json(), "data.period_models_by_game.{$game->id}.0.lineage.artifact_id"))->toBe($artifact->id)
        ->and((float) data_get($daily->json(), "data.period_models_by_game.{$game->id}.0.probabilities.tie"))->toBe(0.2);

    $this->getJson('/api/v2/sports/mlb/predictions?season=2026&from_date=2026-07-30&to_date=2026-07-30')
        ->assertOk()
        ->assertJsonPath('data.0.period_insights.0.state', 'priced_candidate')
        ->assertJsonPath('data.0.period_insights.0.market_available', true)
        ->assertJsonPath('data.0.period_insights.0.candidate_available', true)
        ->assertJsonPath('data.0.period_insights.0.shadow_model_available', true);
});

it('does not elevate a negative no-vig moneyline edge into a bet candidate', function (): void {
    Carbon::setTestNow('2026-07-30 10:00:00');
    [$game, $home, $away] = inningSignalGame(now()->startOfDay());
    $game->update([
        'game_time' => '19:10:00',
        'odds_data' => [
            'bookmakers' => [[
                'key' => 'draftkings',
                'title' => 'DraftKings',
                'markets' => [[
                    'key' => 'h2h',
                    'outcomes' => [
                        ['name' => $home->display_name, 'price' => -250],
                        ['name' => $away->display_name, 'price' => 200],
                    ],
                ]],
            ]],
        ],
        'odds_updated_at' => now(),
    ]);
    inningSignalPrediction($game, 0.52);

    $this->artisan('mlb:generate-daily-picks', [
        '--date' => '2026-07-30',
        '--season' => 2026,
        '--markets' => 'moneyline',
    ])->assertSuccessful();

    $homeCandidate = PickCandidate::query()->where('side', 'home')->firstOrFail();

    expect((float) $homeCandidate->edge_no_vig)->toBeLessThan(0.0)
        ->and(data_get($homeCandidate->feature_snapshot, 'internal_candidate_label'))->toBe('no_play')
        ->and($homeCandidate->risk_flags)->toContain('nonpositive_no_vig_edge');
});

/**
 * @return array{Game,Team,Team}
 */
function inningSignalGame(CarbonInterface $date): array
{
    $home = Team::factory()->create([
        'location' => 'Kansas City',
        'name' => 'Royals',
        'abbreviation' => 'KC',
    ]);
    $away = Team::factory()->create([
        'location' => 'St. Louis',
        'name' => 'Cardinals',
        'abbreviation' => 'STL',
    ]);
    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'game_date' => $date->toDateString(),
        'game_time' => '19:10:00',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'short_name' => 'STL @ KC',
        'probable_home_pitcher_espn_id' => '1001',
        'probable_away_pitcher_espn_id' => '1002',
    ]);

    return [$game, $home, $away];
}

function inningSignalPrediction(Game $game, float $homeWinProbability = 0.61): Prediction
{
    return Prediction::query()->create([
        'game_id' => $game->id,
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'home_team_elo' => 1540,
        'away_team_elo' => 1500,
        'home_pitcher_elo' => 1600,
        'away_pitcher_elo' => 1480,
        'home_combined_elo' => 1570,
        'away_combined_elo' => 1490,
        'predicted_spread' => 1.8,
        'predicted_total' => 9.4,
        'win_probability' => $homeWinProbability,
        'confidence_score' => 58,
        'model_version' => 'test',
        'feature_version' => 'core-v3',
        'blend_version' => 'test',
        'model_metadata' => [],
    ]);
}

/**
 * @return array<string,mixed>
 */
function inningOddsPayload(Team $home, Team $away, bool $includeCore = true, bool $includeInnings = true): array
{
    $markets = [];
    if ($includeCore) {
        $markets[] = [
            'key' => 'h2h',
            'outcomes' => [
                ['name' => $home->display_name, 'price' => -115],
                ['name' => $away->display_name, 'price' => -105],
            ],
        ];
    }
    if ($includeInnings) {
        $markets = [
            ...$markets,
            [
                'key' => 'totals_1st_1_innings',
                'outcomes' => [
                    ['name' => 'Over', 'point' => 0.5, 'price' => -110],
                    ['name' => 'Under', 'point' => 0.5, 'price' => -110],
                ],
            ],
            [
                'key' => 'h2h_1st_3_innings',
                'outcomes' => [
                    ['name' => $home->display_name, 'price' => -105],
                    ['name' => $away->display_name, 'price' => -115],
                ],
            ],
            [
                'key' => 'h2h_1st_5_innings',
                'outcomes' => [
                    ['name' => $home->display_name, 'price' => -110],
                    ['name' => $away->display_name, 'price' => -110],
                ],
            ],
        ];
    }

    return [
        'event_id' => 'mlb-event',
        'commence_time' => '2026-07-30T19:10:00-05:00',
        'home_team' => $home->display_name,
        'away_team' => $away->display_name,
        'bookmakers' => [[
            'key' => 'draftkings',
            'title' => 'DraftKings',
            'markets' => $markets,
        ]],
    ];
}
