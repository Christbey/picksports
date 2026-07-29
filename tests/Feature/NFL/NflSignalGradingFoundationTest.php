<?php

use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Models\ModelRun;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\NflSignalObservation;
use App\Models\PredictionFeatureSnapshot;
use App\Services\NFL\NflSignalGradeReportService;
use App\Services\NFL\NflSignalGradingService;
use App\Services\NFL\NflSignalObservationMaterializer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

function createSignalSnapshot(array $overrides = []): PredictionFeatureSnapshot
{
    $season = (int) ($overrides['season'] ?? 2024);
    $homeScore = (int) ($overrides['home_score'] ?? 27);
    $awayScore = (int) ($overrides['away_score'] ?? 20);
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => $season,
        'week' => $overrides['week'] ?? 8,
        'game_date' => "{$season}-10-20",
        'game_time' => '12:00:00',
        'status' => $overrides['status'] ?? 'STATUS_FINAL',
        'home_score' => $homeScore,
        'away_score' => $awayScore,
    ]);
    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => $overrides['predicted_spread'] ?? 4.0,
        'predicted_total' => $overrides['predicted_total'] ?? 44.0,
        'win_probability' => $overrides['win_probability'] ?? 0.65,
        'confidence_score' => 65,
        'model_version' => 'nfl-model-v1',
        'feature_version' => 'nfl-features-v1',
        'blend_version' => 'nfl-blend-v1',
    ]);
    $modelRun = ModelRun::query()->create([
        'id' => (string) Str::uuid(),
        'sport' => 'nfl',
        'run_type' => 'prediction',
        'model_version' => 'nfl-model-v1',
        'feature_version' => 'nfl-features-v1',
        'blend_version' => 'nfl-blend-v1',
        'config_hash' => hash('sha256', "config-{$season}-{$game->id}"),
        'status' => 'completed',
        'started_at' => Carbon::parse("{$season}-10-20 10:00:00"),
        'completed_at' => Carbon::parse("{$season}-10-20 10:01:00"),
    ]);
    $metadata = $overrides['model_metadata'] ?? [
        'analysis_layer' => [
            'reason_codes' => ['qb_form_home_edge', 'market_total_edge_under'],
            'risk_flags' => ['low_model_confidence'],
            'reason_code_metadata' => [],
            'bet_rule_evaluation' => [
                'matched_rules' => [[
                    'name' => 'total_market_edge_play',
                    'label' => 'Total Market Edge Play',
                    'action' => 'play',
                    'market' => 'total',
                ]],
                'pass_rules' => ['pass_conflicting_or_low_quality'],
            ],
            'validated_signals' => [[
                'name' => 'qb_form_pressure_mismatch',
                'label' => 'QB Form + Pressure Mismatch',
                'market' => 'winner',
                'tier' => 'strong',
                'codes' => ['qb_form_signal', 'weak_ol_vs_blitz_heavy_defense'],
            ]],
            'calculated_edge' => [
                'market_spread' => $overrides['market_home_margin'] ?? 3.0,
                'market_total' => $overrides['market_total'] ?? 45.0,
            ],
        ],
    ];

    return PredictionFeatureSnapshot::query()->create([
        'sport' => 'nfl',
        'prediction_table' => 'nfl_predictions',
        'prediction_id' => $prediction->id,
        'game_id' => $game->id,
        'snapshot_run_id' => (string) Str::uuid(),
        'model_run_id' => $modelRun->id,
        'model_version' => 'nfl-model-v1',
        'feature_version' => 'nfl-features-v1',
        'blend_version' => 'nfl-blend-v1',
        'features' => ['elo_difference' => 40],
        'outputs' => [
            'win_probability' => $overrides['win_probability'] ?? 0.65,
            'baseline_win_probability' => $overrides['baseline_win_probability'] ?? 0.55,
            'predicted_spread' => $overrides['predicted_spread'] ?? 4.0,
            'predicted_total' => $overrides['predicted_total'] ?? 44.0,
            'market_spread' => $overrides['market_home_margin'] ?? 3.0,
            'market_total' => $overrides['market_total'] ?? 45.0,
        ],
        'market_context' => [
            'market_home_margin' => $overrides['market_home_margin'] ?? 3.0,
            'market_total' => $overrides['market_total'] ?? 45.0,
        ],
        'model_metadata' => $metadata,
        'feature_hash' => hash('sha256', "features-{$season}-{$game->id}"),
        'generated_at' => Carbon::parse("{$season}-10-20 10:00:00"),
        'game_start_at' => Carbon::parse("{$season}-10-20 12:00:00"),
        'features_available_at' => Carbon::parse("{$season}-10-20 10:00:00"),
        'pregame_safe' => true,
        'availability_status' => 'observed_pregame',
        'source_timestamps' => ['odds' => "{$season}-10-20T10:00:00Z"],
        'lineage_metadata' => ['point_in_time_verified' => true],
    ]);
}

function createSignalSettlement(PredictionFeatureSnapshot $snapshot, bool $isBet = true): BetSettlement
{
    $decision = BetDecision::query()->create([
        'decision_run_id' => (string) Str::uuid(),
        'model_run_id' => $snapshot->model_run_id,
        'prediction_feature_snapshot_id' => $snapshot->id,
        'sport' => 'nfl',
        'game_table' => 'nfl_games',
        'game_id' => $snapshot->game_id,
        'prediction_table' => 'nfl_predictions',
        'prediction_id' => $snapshot->prediction_id,
        'market_type' => 'moneyline',
        'market_key' => 'h2h',
        'side' => 'home',
        'price' => 100,
        'status' => 'bet',
        'is_public' => false,
        'is_tracking_only' => ! $isBet,
        'is_bet' => $isBet,
        'pregame_safe' => true,
        'decided_at' => $snapshot->generated_at,
        'game_start_at' => $snapshot->game_start_at,
        'decision_hash' => hash('sha256', "decision-{$snapshot->id}-".($isBet ? 'bet' : 'shadow')),
    ]);

    return BetSettlement::query()->create([
        'bet_decision_id' => $decision->id,
        'result_status' => 'win',
        'result_value' => 7,
        'profit_units' => $isBet ? 1.0 : 0.0,
        'closing_price' => -105,
        'clv' => 0.02,
        'graded_at' => now(),
        'settled_at' => now(),
        'metadata' => ['shadow_profit_units' => 1.0],
    ]);
}

it('materializes every NFL snapshot signal atomically with immutable lineage', function () {
    $snapshot = createSignalSnapshot();
    $materializer = app(NflSignalObservationMaterializer::class);

    $first = $materializer->materialize($snapshot);
    $second = $materializer->materialize($snapshot);

    expect($first)->toMatchArray(['created' => 6, 'existing' => 0, 'signals' => 6])
        ->and($second)->toMatchArray(['created' => 0, 'existing' => 6, 'signals' => 6])
        ->and(NflSignalObservation::query()->count())->toBe(6)
        ->and(NflSignalObservation::query()->pluck('signal_type')->unique()->sort()->values()->all())
        ->toBe(['matched_rule', 'pass_rule', 'reason_code', 'risk_flag', 'validated_combo']);

    $observation = NflSignalObservation::query()
        ->where('signal_type', 'reason_code')
        ->where('signal_key', 'qb_form_home_edge')
        ->firstOrFail();

    expect($observation->snapshot_run_id)->toBe($snapshot->snapshot_run_id)
        ->and($observation->model_run_id)->toBe($snapshot->model_run_id)
        ->and($observation->config_hash)->toBe($snapshot->modelRun->config_hash)
        ->and($observation->feature_hash)->toBe($snapshot->feature_hash)
        ->and($observation->observation_hash)->toHaveLength(64)
        ->and($observation->direction)->toBe('home');

    expect(fn () => $observation->update(['label' => 'changed']))
        ->toThrow(LogicException::class, 'immutable');
});

it('grades winner spread total and exact settlement outcomes idempotently', function () {
    $snapshot = createSignalSnapshot();
    createSignalSettlement($snapshot);
    app(NflSignalObservationMaterializer::class)->materialize($snapshot);
    $observation = NflSignalObservation::query()
        ->where('signal_type', 'reason_code')
        ->where('signal_key', 'qb_form_home_edge')
        ->firstOrFail();

    $first = app(NflSignalGradingService::class)->grade($observation);
    $second = app(NflSignalGradingService::class)->grade($observation);

    expect($first)->toMatchArray(['created' => 4, 'updated' => 0, 'skipped' => false])
        ->and($second)->toMatchArray(['created' => 0, 'updated' => 4, 'skipped' => false])
        ->and($observation->grades()->count())->toBe(4);

    $winner = $observation->grades()->where('evaluation_key', 'outcome:winner')->firstOrFail();
    $spread = $observation->grades()->where('evaluation_key', 'outcome:spread')->firstOrFail();
    $total = $observation->grades()->where('evaluation_key', 'outcome:total')->firstOrFail();
    $settlement = $observation->grades()->where('evaluation_source', 'settlement')->firstOrFail();

    expect($winner->result_status)->toBe('win')
        ->and((float) $winner->brier_score)->toEqualWithDelta(0.1225, 0.000001)
        ->and((float) $winner->calibration_lift)->toEqualWithDelta(0.08, 0.000001)
        ->and($spread->result_status)->toBe('win')
        ->and((float) $spread->error_lift)->toEqualWithDelta(1.0, 0.000001)
        ->and($total->result_status)->toBe('loss')
        ->and((float) $total->error_lift)->toEqualWithDelta(-1.0, 0.000001)
        ->and($settlement->is_actual_bet)->toBeTrue()
        ->and((float) $settlement->profit_units)->toEqualWithDelta(1.0, 0.000001)
        ->and((float) $settlement->clv)->toEqualWithDelta(0.02, 0.000001);
});

it('reports settlement metrics and chronological season stability', function () {
    $winningSnapshot = createSignalSnapshot(['season' => 2023]);
    $losingSnapshot = createSignalSnapshot([
        'season' => 2024,
        'home_score' => 17,
        'away_score' => 24,
    ]);

    foreach ([$winningSnapshot, $losingSnapshot] as $snapshot) {
        createSignalSettlement($snapshot);
        app(NflSignalObservationMaterializer::class)->materialize($snapshot);

        NflSignalObservation::query()
            ->where('prediction_feature_snapshot_id', $snapshot->id)
            ->each(fn (NflSignalObservation $observation) => app(NflSignalGradingService::class)->grade($observation));
    }

    $report = app(NflSignalGradeReportService::class)->report([
        'signal_type' => 'reason_code',
        'signal_key' => 'qb_form_home_edge',
    ]);
    $signal = $report['signals'][0];

    expect($signal['observation_count'])->toBe(2)
        ->and($signal['winner_sample'])->toBe(2)
        ->and($signal['winner_accuracy'])->toBe(0.5)
        ->and($signal['ats_sample'])->toBe(2)
        ->and($signal['total_sample'])->toBe(2)
        ->and($signal['settlement_sample'])->toBe(2)
        ->and($signal['roi'])->toBe(1.0)
        ->and($signal['avg_clv'])->toBe(0.02)
        ->and($signal['window_count'])->toBe(2)
        ->and($signal['winner_accuracy_range'])->toBe(1.0)
        ->and($report['windows'])->toHaveCount(2);
});

it('keeps unfinished outcomes ungraded and shadow ROI separate from actual ROI', function () {
    $scheduledSnapshot = createSignalSnapshot([
        'season' => 2025,
        'status' => 'STATUS_SCHEDULED',
    ]);
    app(NflSignalObservationMaterializer::class)->materialize($scheduledSnapshot);
    $scheduledObservation = NflSignalObservation::query()
        ->where('prediction_feature_snapshot_id', $scheduledSnapshot->id)
        ->firstOrFail();

    expect(app(NflSignalGradingService::class)->grade($scheduledObservation))
        ->toMatchArray(['created' => 0, 'updated' => 0, 'skipped' => true])
        ->and($scheduledObservation->grades()->count())->toBe(0);

    $trackingSnapshot = createSignalSnapshot(['season' => 2024]);
    createSignalSettlement($trackingSnapshot, false);
    app(NflSignalObservationMaterializer::class)->materialize($trackingSnapshot);
    NflSignalObservation::query()
        ->where('prediction_feature_snapshot_id', $trackingSnapshot->id)
        ->each(fn (NflSignalObservation $observation) => app(NflSignalGradingService::class)->grade($observation));

    $report = app(NflSignalGradeReportService::class)->report([
        'signal_type' => 'reason_code',
        'signal_key' => 'qb_form_home_edge',
    ]);
    $signal = $report['signals'][0];

    expect($signal['observation_count'])->toBe(2)
        ->and($signal['window_count'])->toBe(1)
        ->and($signal['settlement_sample'])->toBe(0)
        ->and($signal['roi'])->toBeNull()
        ->and($signal['shadow_settlement_sample'])->toBe(1)
        ->and($signal['shadow_roi'])->toBe(1.0);
});

it('runs the materialize grade and JSON report commands', function () {
    $snapshot = createSignalSnapshot();
    createSignalSettlement($snapshot);

    expect(Artisan::call('nfl:materialize-signal-observations', ['--snapshot-id' => [$snapshot->id]]))->toBe(0);
    expect(Artisan::output())->toContain('Materialized 6 NFL signal observation(s)');

    expect(Artisan::call('nfl:grade-signal-observations', ['--season' => 2024]))->toBe(0);
    expect(Artisan::output())->toContain('Graded 6 NFL signal observation(s)');

    expect(Artisan::call('nfl:report-signal-grades', [
        '--signal-type' => 'reason_code',
        '--signal-key' => 'qb_form_home_edge',
        '--json' => true,
    ]))->toBe(0);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(data_get($report, 'signals.0.signal_key'))->toBe('qb_form_home_edge')
        ->and(data_get($report, 'signals.0.winner_accuracy'))->toBe(1);
});
