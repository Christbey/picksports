<?php

use App\Models\BetDecision;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Team as MlbTeam;
use App\Models\ModelArtifact;
use App\Models\NFL\Game as NflGame;
use App\Models\NFL\Team as NflTeam;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Services\ML\ShadowModelOutputRecorder;
use App\Services\Predictions\ModelRunRecorder;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses()->group('ml', 'shadow-feedback');

it('records immutable MLB win spread and total shadow observations from one structured inference', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    [$artifact, $game, $snapshot] = createStructuredMlbShadowFeedbackFixture();

    $first = app(ShadowModelOutputRecorder::class)->record($snapshot);
    $initial = ShadowModelOutput::query()
        ->where('prediction_feature_snapshot_id', $snapshot->id)
        ->orderBy('market_type')
        ->get()
        ->keyBy('market_type');

    expect($first?->market_type)->toBe('win_probability')
        ->and($initial)->toHaveCount(3)
        ->and($initial->keys()->sort()->values()->all())->toBe(['spread', 'total', 'win_probability'])
        ->and($initial['win_probability']->baseline_output)->toBe(0.54)
        ->and($initial['win_probability']->challenger_output)->toBe(0.62)
        ->and($initial['spread']->baseline_output)->toBe(1.2)
        ->and($initial['spread']->challenger_output)->toBe(2.4)
        ->and($initial['total']->baseline_output)->toBe(8.0)
        ->and($initial['total']->challenger_output)->toBe(9.1)
        ->and(data_get($initial['spread']->explanation, 'market_promotion.spread'))->toBeTrue()
        ->and(data_get($initial['spread']->explanation, 'artifact_id'))->toBe($artifact->id)
        ->and(data_get($initial['spread']->explanation, 'dataset_hash'))->toBe($artifact->dataset_hash)
        ->and(data_get($initial['spread']->explanation, 'public_output_changed'))->toBeFalse();

    $metadata = $snapshot->model_metadata;
    data_set($metadata, 'shadow_inference.challenger_outputs.predicted_spread', 99.0);
    $snapshot->update(['model_metadata' => $metadata]);
    app(ShadowModelOutputRecorder::class)->record($snapshot->refresh());

    expect(ShadowModelOutput::query()
        ->where('prediction_feature_snapshot_id', $snapshot->id)
        ->count())->toBe(3)
        ->and((float) ShadowModelOutput::query()
            ->where('prediction_feature_snapshot_id', $snapshot->id)
            ->where('market_type', 'spread')
            ->value('challenger_output'))->toBe(2.4)
        ->and($game->exists)->toBeTrue();
});

it('records every artifact in a shadow cohort without changing public outputs', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    [$champion, $game, $snapshot] = createStructuredMlbShadowFeedbackFixture();
    $challenger = createShadowFeedbackArtifact(
        $champion->training_run_id,
        'mlb',
        [],
        now()->subHour(),
    );
    $challenger->update([
        'status' => 'challenger',
        'promotion_decision' => null,
        'promoted_at' => null,
    ]);

    $championContext = (array) data_get($snapshot->model_metadata, 'shadow_inference');
    $challengerContext = [
        ...$championContext,
        'artifact_id' => $challenger->id,
        'artifact_hash' => $challenger->artifact_hash,
        'training_run_id' => $challenger->training_run_id,
        'dataset_hash' => $challenger->dataset_hash,
        'challenger_outputs' => [
            ...(array) $championContext['challenger_outputs'],
            'win_probability' => 0.59,
            'predicted_spread' => 1.8,
            'predicted_total' => 8.6,
        ],
        'market_promotion' => [
            'win_probability' => false,
            'spread' => false,
            'total' => false,
        ],
        'reason' => 'active_challenger_tracking_shadow',
    ];
    $metadata = (array) $snapshot->model_metadata;
    data_set($metadata, 'shadow_inference.cohort', [$championContext, $challengerContext]);
    $snapshot->update(['model_metadata' => $metadata]);

    app(ShadowModelOutputRecorder::class)->record($snapshot->refresh());

    $outputs = ShadowModelOutput::query()
        ->where('prediction_feature_snapshot_id', $snapshot->id)
        ->get();

    expect($outputs)->toHaveCount(6)
        ->and($outputs->pluck('model_artifact_id')->unique()->sort()->values()->all())
        ->toBe(collect([$champion->id, $challenger->id])->sort()->values()->all())
        ->and($outputs->where('model_artifact_id', $champion->id)->pluck('status')->unique()->all())
        ->toBe(['promoted_shadow'])
        ->and($outputs->where('model_artifact_id', $challenger->id)->pluck('status')->unique()->all())
        ->toBe(['shadow'])
        ->and($outputs->every(
            fn (ShadowModelOutput $output): bool => data_get(
                $output->explanation,
                'public_output_changed',
            ) === false,
        ))->toBeTrue()
        ->and($game->exists)->toBeTrue();
});

it('records and settles private MLB moneyline run line and total decisions with pushes and CLV', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    config(['mlb_ml.shadow.max_uncertainty' => 0.10]);
    [$artifact, $game, $snapshot] = createStructuredMlbShadowFeedbackFixture();
    app(ShadowModelOutputRecorder::class)->record($snapshot);

    createShadowFeedbackQuote($game, 'h2h', 'home', null, 100, 0.50, '2026-08-01 16:50:00');
    createShadowFeedbackQuote($game, 'spreads', 'home', -2.0, 105, 0.50, '2026-08-01 16:50:00');
    createShadowFeedbackQuote($game, 'totals', 'over', 8.0, -105, 0.50, '2026-08-01 16:50:00');
    createShadowFeedbackQuote($game, 'h2h', 'home', null, -120, 0.55, '2026-08-01 18:50:00');
    createShadowFeedbackQuote($game, 'spreads', 'home', -2.5, -110, 0.52, '2026-08-01 18:50:00');
    createShadowFeedbackQuote($game, 'totals', 'over', 8.5, -110, 0.52, '2026-08-01 18:50:00');
    createShadowFeedbackQuote($game, 'h2h', 'home', null, -200, 0.67, '2026-08-01 18:55:00', bookmaker: 'otherbook');
    createShadowFeedbackQuote($game, 'spreads', 'home', -3.5, -110, 0.52, '2026-08-01 18:55:00', bookmaker: 'otherbook');
    createShadowFeedbackQuote($game, 'totals', 'over', 9.5, -110, 0.52, '2026-08-01 18:55:00', bookmaker: 'otherbook');

    $this->artisan('sports:record-shadow-bet-decisions', [
        '--sport' => 'mlb',
        '--artifact' => $artifact->id,
    ])
        ->expectsOutput('Recorded 3 new immutable shadow decision(s).')
        ->assertSuccessful();

    $decisions = BetDecision::query()
        ->where('model_artifact_id', $artifact->id)
        ->orderBy('market_type')
        ->get()
        ->keyBy('market_type');

    expect($decisions)->toHaveCount(3)
        ->and($decisions->every(fn (BetDecision $decision): bool => $decision->is_public === false))->toBeTrue()
        ->and($decisions->every(fn (BetDecision $decision): bool => $decision->is_tracking_only === true))->toBeTrue()
        ->and($decisions->every(fn (BetDecision $decision): bool => $decision->is_bet === true))->toBeTrue()
        ->and($decisions['moneyline']->market_key)->toBe('h2h')
        ->and($decisions['moneyline']->side)->toBe('home')
        ->and($decisions['spread']->market_key)->toBe('spreads')
        ->and(data_get($decisions['spread']->explanation, 'market_display_type'))->toBe('run_line')
        ->and((float) $decisions['spread']->line)->toBe(-2.0)
        ->and($decisions['total']->market_key)->toBe('totals')
        ->and($decisions['total']->side)->toBe('over')
        ->and((float) $decisions['total']->line)->toBe(8.0);

    $this->artisan('sports:record-shadow-bet-decisions', [
        '--sport' => 'mlb',
        '--artifact' => $artifact->id,
    ])
        ->expectsOutput('Recorded 0 new immutable shadow decision(s).')
        ->assertSuccessful();

    expect(BetDecision::query()->where('model_artifact_id', $artifact->id)->count())->toBe(3);

    $game->update([
        'status' => 'STATUS_FINAL',
        'home_score' => 5,
        'away_score' => 3,
    ]);
    Carbon::setTestNow('2026-08-02 04:30:00');

    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'mlb'])
        ->expectsOutput('Settled 3 decision(s).')
        ->assertSuccessful();

    $settlements = BetDecision::query()
        ->where('model_artifact_id', $artifact->id)
        ->with('settlement')
        ->get()
        ->keyBy('market_type');

    expect($settlements['moneyline']->settlement->result_status)->toBe('win')
        ->and((float) $settlements['moneyline']->settlement->profit_units)->toBe(1.0)
        ->and((float) $settlements['moneyline']->settlement->clv)->toBe(0.05)
        ->and(data_get($settlements['moneyline']->settlement->metadata, 'clv_type'))->toBe('probability')
        ->and(data_get($settlements['moneyline']->settlement->metadata, 'closing_quote_selection'))->toBe('exact_bookmaker')
        ->and(data_get($settlements['moneyline']->settlement->metadata, 'closing_bookmaker'))->toBe('testbook')
        ->and($settlements['spread']->settlement->result_status)->toBe('push')
        ->and((float) $settlements['spread']->settlement->profit_units)->toBe(0.0)
        ->and((float) $settlements['spread']->settlement->closing_line)->toBe(-2.5)
        ->and((float) $settlements['spread']->settlement->clv)->toBe(0.5)
        ->and($settlements['total']->settlement->result_status)->toBe('push')
        ->and((float) $settlements['total']->settlement->profit_units)->toBe(0.0)
        ->and((float) $settlements['total']->settlement->closing_line)->toBe(8.5)
        ->and((float) $settlements['total']->settlement->clv)->toBe(0.5);

    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'mlb'])
        ->expectsOutput('Settled 0 decision(s).')
        ->assertSuccessful();
});

it('records a labeled median consensus CLV fallback when the entry bookmaker has no closing quote', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    config(['mlb_ml.shadow.max_uncertainty' => 0.10]);
    [$artifact, $game, $snapshot] = createStructuredMlbShadowFeedbackFixture();
    app(ShadowModelOutputRecorder::class)->record($snapshot);

    createShadowFeedbackQuote($game, 'h2h', 'home', null, 100, 0.50, '2026-08-01 16:50:00');

    $this->artisan('sports:record-shadow-bet-decisions', [
        '--sport' => 'mlb',
        '--artifact' => $artifact->id,
    ])->assertSuccessful();

    createShadowFeedbackQuote($game, 'h2h', 'home', null, -115, 0.535, '2026-08-01 18:40:00', bookmaker: 'book-a');
    createShadowFeedbackQuote($game, 'h2h', 'home', null, -130, 0.565, '2026-08-01 18:45:00', bookmaker: 'book-b');
    createShadowFeedbackQuote($game, 'h2h', 'home', null, -145, 0.595, '2026-08-01 18:50:00', bookmaker: 'book-c');

    $game->update([
        'status' => 'STATUS_FINAL',
        'home_score' => 5,
        'away_score' => 3,
    ]);
    Carbon::setTestNow('2026-08-02 04:30:00');

    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'mlb'])->assertSuccessful();

    $settlement = BetDecision::query()
        ->where('model_artifact_id', $artifact->id)
        ->where('market_type', 'moneyline')
        ->with('settlement')
        ->firstOrFail()
        ->settlement;

    expect((float) $settlement->clv)->toBe(0.065)
        ->and((float) $settlement->closing_price)->toBe(-130.0)
        ->and(data_get($settlement->metadata, 'closing_quote_selection'))->toBe('consensus_fallback')
        ->and(data_get($settlement->metadata, 'entry_bookmaker'))->toBe('testbook')
        ->and(data_get($settlement->metadata, 'closing_bookmaker'))->toBe('book-b')
        ->and(data_get($settlement->metadata, 'consensus_bookmaker_count'))->toBe(3);
});

it('keeps refreshed MLB snapshot shadow and decision identities immutable', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    config(['mlb_ml.shadow.max_uncertainty' => 0.10]);
    [$artifact, $game, $firstSnapshot] = createStructuredMlbShadowFeedbackFixture();
    app(ShadowModelOutputRecorder::class)->record($firstSnapshot);

    createShadowFeedbackQuote($game, 'h2h', 'home', null, 100, 0.50, '2026-08-01 16:50:00');
    createShadowFeedbackQuote($game, 'spreads', 'home', -2.0, 105, 0.50, '2026-08-01 16:50:00');
    createShadowFeedbackQuote($game, 'totals', 'over', 8.0, -105, 0.50, '2026-08-01 16:50:00');

    $this->artisan('sports:record-shadow-bet-decisions', [
        '--sport' => 'mlb',
        '--artifact' => $artifact->id,
    ])->assertSuccessful();

    $secondSnapshot = createShadowFeedbackSnapshot(
        $artifact->training_run_id,
        $artifact,
        $game,
        (array) data_get($firstSnapshot->model_metadata, 'shadow_inference'),
        [
            'generated_at' => Carbon::parse('2026-08-01 17:30:00'),
            'features_available_at' => Carbon::parse('2026-08-01 17:25:00'),
            'feature_hash' => hash('sha256', 'refreshed-snapshot-'.$game->id),
        ],
    );
    app(ShadowModelOutputRecorder::class)->record($secondSnapshot);

    createShadowFeedbackQuote($game, 'h2h', 'home', null, -105, 0.512, '2026-08-01 17:20:00');
    createShadowFeedbackQuote($game, 'spreads', 'home', -2.0, -105, 0.512, '2026-08-01 17:20:00');
    createShadowFeedbackQuote($game, 'totals', 'over', 8.0, -105, 0.512, '2026-08-01 17:20:00');

    $this->artisan('sports:record-shadow-bet-decisions', [
        '--sport' => 'mlb',
        '--artifact' => $artifact->id,
    ])->assertSuccessful();

    $decisions = BetDecision::query()
        ->where('model_artifact_id', $artifact->id)
        ->get();

    expect($decisions)->toHaveCount(6)
        ->and($decisions->pluck('prediction_feature_snapshot_id')->unique())->toHaveCount(2)
        ->and($decisions->pluck('shadow_model_output_id')->unique())->toHaveCount(6)
        ->and($decisions->pluck('decision_hash')->unique())->toHaveCount(6);
});

it('keeps MLB markets as no-bets when uncertainty quote or market promotion gates fail', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    config(['mlb_ml.shadow.max_uncertainty' => 0.10]);
    [$artifact, $game, $snapshot] = createStructuredMlbShadowFeedbackFixture([
        'market_promotion' => [
            'win_probability' => true,
            'spread' => false,
            'total' => true,
        ],
        'challenger_outputs' => [
            'uncertainty' => 0.20,
        ],
    ]);
    app(ShadowModelOutputRecorder::class)->record($snapshot);

    createShadowFeedbackQuote($game, 'h2h', 'home', null, 100, 0.50, '2026-08-01 16:50:00');
    createShadowFeedbackQuote($game, 'spreads', 'home', -1.5, 100, 0.50, '2026-08-01 16:50:00');

    $this->artisan('sports:record-shadow-bet-decisions', [
        '--sport' => 'mlb',
        '--artifact' => $artifact->id,
    ])->assertSuccessful();

    $decisions = BetDecision::query()
        ->where('model_artifact_id', $artifact->id)
        ->get()
        ->keyBy('market_type');

    expect($decisions)->toHaveCount(3)
        ->and($decisions->every(fn (BetDecision $decision): bool => $decision->is_bet === false))->toBeTrue()
        ->and($decisions->every(fn (BetDecision $decision): bool => $decision->is_public === false))->toBeTrue()
        ->and($decisions['moneyline']->eligibility_reasons)->toContain('model_uncertainty_above_threshold')
        ->and($decisions['spread']->eligibility_reasons)->toContain('market_model_not_promoted_at_decision_time')
        ->and($decisions['total']->eligibility_reasons)->toContain('pregame_market_quote_missing')
        ->and($decisions['total']->edge)->toBeNull();
});

it('uses only the MLB model reference line and prefers its referenced bookmaker', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    config(['mlb_ml.shadow.max_uncertainty' => 0.10]);
    [$artifact, $game, $snapshot] = createStructuredMlbShadowFeedbackFixture();
    app(ShadowModelOutputRecorder::class)->record($snapshot);

    createShadowFeedbackQuote(
        $game,
        'h2h',
        'home',
        null,
        105,
        0.52,
        '2026-08-01 16:40:00',
        bookmaker: 'testbook',
    );
    createShadowFeedbackQuote(
        $game,
        'h2h',
        'home',
        null,
        120,
        0.45,
        '2026-08-01 16:50:00',
        bookmaker: 'otherbook',
    );
    createShadowFeedbackQuote(
        $game,
        'spreads',
        'home',
        -2.0,
        105,
        0.52,
        '2026-08-01 16:40:00',
        bookmaker: 'testbook',
    );
    createShadowFeedbackQuote(
        $game,
        'spreads',
        'home',
        -2.0,
        120,
        0.45,
        '2026-08-01 16:50:00',
        bookmaker: 'otherbook',
    );
    createShadowFeedbackQuote(
        $game,
        'spreads',
        'home',
        -1.5,
        125,
        0.44,
        '2026-08-01 16:50:00',
        bookmaker: 'otherbook',
    );
    createShadowFeedbackQuote(
        $game,
        'totals',
        'over',
        8.0,
        105,
        0.52,
        '2026-08-01 16:40:00',
        bookmaker: 'testbook',
    );
    createShadowFeedbackQuote(
        $game,
        'totals',
        'over',
        8.0,
        120,
        0.45,
        '2026-08-01 16:50:00',
        bookmaker: 'otherbook',
    );
    createShadowFeedbackQuote(
        $game,
        'totals',
        'over',
        8.5,
        125,
        0.44,
        '2026-08-01 16:50:00',
        bookmaker: 'otherbook',
    );

    $this->artisan('sports:record-shadow-bet-decisions', [
        '--sport' => 'mlb',
        '--artifact' => $artifact->id,
    ])->assertSuccessful();

    $decisions = BetDecision::query()
        ->where('model_artifact_id', $artifact->id)
        ->get()
        ->keyBy('market_type');

    expect($decisions['moneyline']->bookmaker)->toBe('otherbook')
        ->and($decisions['moneyline']->price)->toBe(120)
        ->and($decisions['spread']->bookmaker)->toBe('testbook')
        ->and((float) $decisions['spread']->line)->toBe(-2.0)
        ->and($decisions['spread']->price)->toBe(105)
        ->and((float) $decisions['spread']->edge)->toBe(0.08)
        ->and((float) data_get($decisions['spread']->explanation, 'model_market_reference_line'))->toBe(-2.0)
        ->and($decisions['total']->bookmaker)->toBe('testbook')
        ->and((float) $decisions['total']->line)->toBe(8.0)
        ->and($decisions['total']->price)->toBe(105)
        ->and((float) $decisions['total']->edge)->toBe(0.06)
        ->and((float) data_get($decisions['total']->explanation, 'model_market_reference_line'))->toBe(8.0);
});

it('refuses MLB spread and total edges when offered lines mismatch the model reference', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    config(['mlb_ml.shadow.max_uncertainty' => 0.10]);
    [$artifact, $game, $snapshot] = createStructuredMlbShadowFeedbackFixture([
        'challenger_outputs' => [
            'home_cover_probability' => 0.40,
        ],
    ]);
    app(ShadowModelOutputRecorder::class)->record($snapshot);

    createShadowFeedbackQuote($game, 'h2h', 'home', null, 100, 0.50, '2026-08-01 16:50:00');
    createShadowFeedbackQuote($game, 'spreads', 'away', 1.5, 120, 0.42, '2026-08-01 16:50:00');
    createShadowFeedbackQuote($game, 'totals', 'over', 8.5, 120, 0.42, '2026-08-01 16:50:00');

    $this->artisan('sports:record-shadow-bet-decisions', [
        '--sport' => 'mlb',
        '--artifact' => $artifact->id,
    ])->assertSuccessful();

    $decisions = BetDecision::query()
        ->where('model_artifact_id', $artifact->id)
        ->get()
        ->keyBy('market_type');

    expect($decisions['moneyline']->is_bet)->toBeTrue()
        ->and($decisions['spread']->is_bet)->toBeFalse()
        ->and($decisions['spread']->side)->toBe('away')
        ->and($decisions['spread']->eligibility_reasons)->toContain('model_market_line_mismatch')
        ->and((float) data_get($decisions['spread']->explanation, 'model_market_reference_line'))->toBe(2.0)
        ->and($decisions['spread']->game_odds_snapshot_id)->toBeNull()
        ->and($decisions['spread']->line)->toBeNull()
        ->and($decisions['spread']->edge)->toBeNull()
        ->and($decisions['total']->is_bet)->toBeFalse()
        ->and($decisions['total']->eligibility_reasons)->toContain('model_market_line_mismatch')
        ->and($decisions['total']->game_odds_snapshot_id)->toBeNull()
        ->and($decisions['total']->line)->toBeNull()
        ->and($decisions['total']->edge)->toBeNull()
        ->and($decisions->every(fn (BetDecision $decision): bool => $decision->is_public === false))->toBeTrue();
});

it('never converts a reconstructed MLB shadow inference into a retrospective bet', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    config(['mlb_ml.shadow.max_uncertainty' => 0.10]);
    [$artifact, $game, $snapshot] = createStructuredMlbShadowFeedbackFixture(
        snapshotOverrides: [
            'availability_status' => 'verified_reconstruction',
        ],
    );
    app(ShadowModelOutputRecorder::class)->record($snapshot);

    createShadowFeedbackQuote($game, 'h2h', 'home', null, 100, 0.50, '2026-08-01 16:50:00');
    createShadowFeedbackQuote($game, 'spreads', 'home', -1.5, 100, 0.50, '2026-08-01 16:50:00');
    createShadowFeedbackQuote($game, 'totals', 'over', 8.5, 100, 0.50, '2026-08-01 16:50:00');

    $this->artisan('sports:record-shadow-bet-decisions', [
        '--sport' => 'mlb',
        '--artifact' => $artifact->id,
    ])->assertSuccessful();

    expect(BetDecision::query()
        ->where('model_artifact_id', $artifact->id)
        ->get()
        ->every(fn (BetDecision $decision): bool => ! $decision->is_bet
            && in_array('historical_reconstruction_not_bet_eligible', $decision->eligibility_reasons, true)))
        ->toBeTrue();
});

it('preserves the legacy NFL single-market shadow metadata contract', function () {
    Carbon::setTestNow('2026-09-01 12:00:00');
    config(['nfl_ml.shadow.max_uncertainty' => null]);
    $trainingRun = app(ModelRunRecorder::class)->create(
        sport: 'nfl',
        runType: 'training',
        modelVersion: 'nfl-legacy-shadow-v1',
        featureVersion: 'nfl-pregame-ml-v3',
        blendVersion: 'nfl-tabular-v1',
        status: 'completed',
        completedAt: now()->subDay(),
    );
    $artifact = createShadowFeedbackArtifact(
        $trainingRun->id,
        'nfl',
        ['win_probability'],
        now()->subHours(2),
    );
    $game = NflGame::factory()->create([
        'home_team_id' => NflTeam::factory(),
        'away_team_id' => NflTeam::factory(),
        'game_date' => '2026-09-10',
        'game_time' => '19:20:00',
        'status' => 'STATUS_SCHEDULED',
    ]);
    $snapshot = createShadowFeedbackSnapshot($trainingRun->id, $artifact, $game, [
        'baseline_output' => 0.49,
        'challenger_output' => 0.61,
        'market_type' => 'win_probability',
    ], [
        'sport' => 'nfl',
        'prediction_table' => 'nfl_predictions',
        'generated_at' => Carbon::parse('2026-09-10 17:00:00'),
        'game_start_at' => Carbon::parse('2026-09-10 19:20:00'),
        'features_available_at' => Carbon::parse('2026-09-10 16:55:00'),
    ]);

    app(ShadowModelOutputRecorder::class)->record($snapshot);
    app(ShadowModelOutputRecorder::class)->record($snapshot);

    $shadow = ShadowModelOutput::query()
        ->where('prediction_feature_snapshot_id', $snapshot->id)
        ->firstOrFail();

    expect(ShadowModelOutput::query()
        ->where('prediction_feature_snapshot_id', $snapshot->id)
        ->count())->toBe(1)
        ->and($shadow->market_type)->toBe('win_probability')
        ->and($shadow->baseline_output)->toBe(0.49)
        ->and($shadow->challenger_output)->toBe(0.61);

    createShadowFeedbackQuote(
        $game,
        'h2h',
        'home',
        null,
        110,
        0.50,
        '2026-09-10 16:50:00',
        'nfl',
    );

    $this->artisan('sports:record-shadow-bet-decisions', [
        '--sport' => 'nfl',
        '--artifact' => $artifact->id,
    ])->assertSuccessful();

    $decision = BetDecision::query()->where('shadow_model_output_id', $shadow->id)->firstOrFail();
    expect($decision->market_type)->toBe('moneyline')
        ->and($decision->is_bet)->toBeTrue()
        ->and($decision->is_public)->toBeFalse()
        ->and($decision->is_tracking_only)->toBeTrue()
        ->and($decision->eligibility_reasons)->toBe([]);
});

/**
 * @param  array<string, mixed>  $shadowOverrides
 * @param  array<string, mixed>  $snapshotOverrides
 * @return array{ModelArtifact, MlbGame, PredictionFeatureSnapshot}
 */
function createStructuredMlbShadowFeedbackFixture(
    array $shadowOverrides = [],
    array $snapshotOverrides = [],
): array {
    $trainingRun = app(ModelRunRecorder::class)->create(
        sport: 'mlb',
        runType: 'training',
        modelVersion: 'mlb-tabular-shadow-v1',
        featureVersion: 'mlb-pregame-ml-v1',
        blendVersion: 'mlb-tabular-v1',
        status: 'completed',
        completedAt: Carbon::parse('2026-07-28 12:00:00'),
    );
    $artifact = createShadowFeedbackArtifact(
        $trainingRun->id,
        'mlb',
        ['win_probability', 'spread', 'total'],
        Carbon::parse('2026-08-01 16:00:00'),
    );
    $game = MlbGame::factory()->create([
        'home_team_id' => MlbTeam::factory(),
        'away_team_id' => MlbTeam::factory(),
        'season' => 2026,
        'game_date' => '2026-08-01',
        'game_time' => '19:00:00',
        'status' => 'STATUS_SCHEDULED',
        'home_score' => null,
        'away_score' => null,
    ]);
    $shadow = array_replace_recursive([
        'artifact_id' => $artifact->id,
        'artifact_hash' => $artifact->artifact_hash,
        'training_run_id' => $trainingRun->id,
        'model_run_id' => (string) Str::uuid(),
        'config_hash' => $trainingRun->config_hash,
        'dataset_hash' => $artifact->dataset_hash,
        'feature_hash' => hash('sha256', 'mlb-shadow-feature-'.$game->id),
        'baseline_outputs' => [
            'win_probability' => 0.54,
            'predicted_spread' => 1.2,
            'predicted_total' => 8.0,
        ],
        'challenger_outputs' => [
            'win_probability' => 0.62,
            'predicted_spread' => 2.4,
            'predicted_total' => 9.1,
            'home_cover_probability' => 0.60,
            'over_probability' => 0.58,
            'uncertainty' => 0.04,
        ],
        'market_promotion' => [
            'win_probability' => true,
            'spread' => true,
            'total' => true,
        ],
        'public_output_changed' => false,
    ], $shadowOverrides);
    $snapshot = createShadowFeedbackSnapshot(
        $trainingRun->id,
        $artifact,
        $game,
        $shadow,
        $snapshotOverrides,
    );

    return [$artifact, $game, $snapshot];
}

/**
 * @param  list<string>  $promotedMarkets
 */
function createShadowFeedbackArtifact(
    string $trainingRunId,
    string $sport,
    array $promotedMarkets,
    CarbonInterface $promotedAt,
): ModelArtifact {
    $id = (string) Str::uuid();

    return ModelArtifact::query()->create([
        'id' => $id,
        'training_run_id' => $trainingRunId,
        'sport' => $sport,
        'market_type' => count($promotedMarkets) === 1 ? $promotedMarkets[0] : 'multi_market',
        'model_type' => $sport.'_tabular_bundle',
        'model_version' => $sport.'-tabular-shadow-v1',
        'feature_version' => $sport.'-pregame-ml-v1',
        'dataset_hash' => hash('sha256', 'dataset-'.$id),
        'artifact_path' => storage_path("app/ml/tests/{$id}.zip"),
        'artifact_hash' => hash('sha256', 'artifact-'.$id),
        'status' => 'promoted',
        'promotion_decision' => [
            'promoted_markets' => $promotedMarkets,
        ],
        'promoted_at' => $promotedAt,
    ]);
}

/**
 * @param  array<string, mixed>  $shadow
 * @param  array<string, mixed>  $overrides
 */
function createShadowFeedbackSnapshot(
    string $modelRunId,
    ModelArtifact $artifact,
    MlbGame|NflGame $game,
    array $shadow,
    array $overrides = [],
): PredictionFeatureSnapshot {
    $sport = $overrides['sport'] ?? $artifact->sport;
    $generatedAt = $overrides['generated_at'] ?? Carbon::parse('2026-08-01 17:00:00');
    $gameStartAt = $overrides['game_start_at'] ?? Carbon::parse('2026-08-01 19:00:00');
    $attributes = array_replace([
        'sport' => $sport,
        'prediction_table' => $sport.'_predictions',
        'prediction_id' => $game->id,
        'game_id' => $game->id,
        'snapshot_run_id' => (string) Str::uuid(),
        'model_run_id' => $modelRunId,
        'model_version' => 'rules-v1',
        'feature_version' => $artifact->feature_version,
        'blend_version' => 'baseline-v1',
        'features' => [
            'home_elo' => 1510,
            'feature_market_home_spread' => 2.0,
            'feature_market_total' => 8.0,
        ],
        'outputs' => [],
        'market_context' => [
            'bookmaker' => 'testbook',
            'market_home_margin' => 2.0,
            'bookmaker_home_line' => -2.0,
            'market_total' => 8.0,
        ],
        'model_metadata' => [
            'shadow_inference' => [
                'artifact_id' => $artifact->id,
                ...$shadow,
            ],
        ],
        'feature_hash' => hash('sha256', 'snapshot-'.$sport.'-'.$game->id),
        'generated_at' => $generatedAt,
        'game_start_at' => $gameStartAt,
        'features_available_at' => $overrides['features_available_at']
            ?? $generatedAt->copy()->subMinutes(5),
        'pregame_safe' => true,
        'availability_status' => 'observed_pregame',
    ], $overrides);
    unset($attributes['sport_override']);

    return PredictionFeatureSnapshot::query()->create($attributes);
}

function createShadowFeedbackQuote(
    MlbGame|NflGame $game,
    string $marketKey,
    string $side,
    ?float $line,
    int $price,
    float $noVigProbability,
    string $capturedAt,
    string $sport = 'mlb',
    string $bookmaker = 'testbook',
): MarketQuote {
    $captured = Carbon::parse($capturedAt);
    $gameStart = Carbon::parse($game->game_date->toDateString().' '.$game->game_time);
    $oddsSnapshot = GameOddsSnapshot::query()->create([
        'sport' => $sport,
        'game_table' => $sport.'_games',
        'game_id' => $game->id,
        'source' => 'test',
        'commence_time' => $gameStart,
        'captured_at' => $captured,
        'payload_hash' => hash('sha256', implode('|', [
            $sport,
            $game->id,
            $marketKey,
            $side,
            $line,
            $price,
            $capturedAt,
            $bookmaker,
        ])),
        'odds_data' => [],
    ]);

    return MarketQuote::query()->create([
        'game_odds_snapshot_id' => $oddsSnapshot->id,
        'sport' => $sport,
        'game_table' => $sport.'_games',
        'game_id' => $game->id,
        'source' => 'test',
        'bookmaker_key' => $bookmaker,
        'market_key' => $marketKey,
        'side' => $side,
        'line' => $line,
        'price' => $price,
        'implied_probability' => $noVigProbability,
        'no_vig_probability' => $noVigProbability,
        'commence_time' => $gameStart,
        'captured_at' => $captured,
        'is_pregame' => true,
        'quote_hash' => hash('sha256', implode('|', [
            'quote',
            $sport,
            $game->id,
            $marketKey,
            $side,
            $line,
            $price,
            $capturedAt,
            $bookmaker,
        ])),
    ]);
}
