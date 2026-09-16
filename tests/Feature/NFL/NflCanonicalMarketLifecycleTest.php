<?php

use App\Actions\NFL\GenerateCanonicalPrediction;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Models\CalculationRelease;
use App\Models\CanonicalPrediction;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Models\SportEvent;
use App\Services\NFL\NflPredictionDispositionRecorder;
use App\Services\NFL\NflReleasedBetDecisionRecorder;
use App\Services\NFL\Predictions\NflCalculationReleaseRegistrar;
use App\Services\NFL\Predictions\NflCanonicalCutoverReadinessService;
use App\Services\OddsApi\GameOddsSnapshotRecorder;
use App\Services\Predictions\CanonicalSportCutoverReadinessService;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use Illuminate\Support\Carbon;

function nflCanonicalMarketFixture(string $seasonType = '2'): array
{
    $startsAt = now()->addHours(8);
    $event = SportEvent::factory()->create([
        'sport' => 'nfl',
        'season' => 2026,
        'season_type' => $seasonType,
        'week' => 2,
        'starts_at' => $startsAt,
        'status' => 'STATUS_SCHEDULED',
    ]);
    $home = Team::factory()->create([
        'location' => 'Chicago',
        'name' => 'Bears',
        'elo_rating' => 1620,
    ]);
    $away = Team::factory()->create([
        'location' => 'Detroit',
        'name' => 'Lions',
        'elo_rating' => 1440,
    ]);

    foreach ([[$home, 32, 18, 14, 2], [$away, 19, 29, -10, -1]] as [$team, $scored, $allowed, $rating, $turnovers]) {
        TeamMetric::query()->create([
            'team_id' => $team->getKey(),
            'season' => 2026,
            'season_type' => $seasonType === '3' ? '2' : $seasonType,
            'wins' => 9,
            'losses' => 3,
            'offensive_rating' => 110,
            'defensive_rating' => 95,
            'net_rating' => $rating,
            'points_per_game' => $scored,
            'points_allowed_per_game' => $allowed,
            'turnover_differential' => $turnovers,
            'recent_form_rating' => $rating / 2,
            'injury_adjusted_team_rating' => $team->elo_rating,
            'injury_total_adjustment' => 0,
            'rest_travel_fatigue' => 0,
            'predictive_rating' => $rating,
            'offensive_true_epa_per_play' => $team->is($home) ? 0.12 : 0.04,
            'defensive_true_epa_per_play' => $team->is($home) ? -0.02 : 0.01,
            'net_true_epa_per_play' => $team->is($home) ? 0.14 : 0.03,
            'calculation_date' => now()->toDateString(),
        ]);
    }

    $game = Game::factory()->create([
        'sport_event_id' => $event->getKey(),
        'home_team_id' => $home->getKey(),
        'away_team_id' => $away->getKey(),
        'season' => 2026,
        'season_type' => $seasonType,
        'week' => 2,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => $startsAt,
        'game_time' => $startsAt->format('H:i:s'),
        'home_score' => null,
        'away_score' => null,
    ]);

    return compact('event', 'game', 'home', 'away');
}

function recordNflLegacyEpaDisposition(array $fixture, bool $applied = true): Prediction
{
    $prediction = Prediction::factory()->create([
        'game_id' => $fixture['game']->getKey(),
        'model_metadata' => [
            'true_epa' => [
                'enabled' => true,
                'applied' => $applied,
                'reason' => $applied ? 'applied' : 'missing_net_true_epa',
            ],
            'analysis_layer' => [
                'applied' => true,
                'eligibility' => $applied
                    ? ['status' => 'candidate', 'eligible' => true, 'data_reasons' => []]
                    : ['status' => 'hold', 'eligible' => false, 'data_reasons' => ['missing_true_epa']],
            ],
        ],
    ]);
    recordNflPredictionFeatureSnapshot($fixture, $prediction);
    app(NflPredictionDispositionRecorder::class)->record($prediction);

    return $prediction;
}

function recordNflMarketSnapshot(
    array $fixture,
    float $homeLine = -3.0,
    float $total = 44.5,
    ?string $providerObservedAt = null,
): void {
    $oddsData = [
        'home_team' => 'Chicago Bears',
        'away_team' => 'Detroit Lions',
        'bookmakers' => [[
            'key' => 'testbook',
            'title' => 'Test Book',
            ...($providerObservedAt === null ? [] : ['last_update' => $providerObservedAt]),
            'markets' => [
                [
                    'key' => 'h2h',
                    'outcomes' => [
                        ['name' => 'Chicago Bears', 'price' => -150],
                        ['name' => 'Detroit Lions', 'price' => 130],
                    ],
                ],
                [
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'Chicago Bears', 'point' => $homeLine, 'price' => -110],
                        ['name' => 'Detroit Lions', 'point' => -$homeLine, 'price' => -110],
                    ],
                ],
                [
                    'key' => 'totals',
                    'outcomes' => [
                        ['name' => 'Over', 'point' => $total, 'price' => -110],
                        ['name' => 'Under', 'point' => $total, 'price' => -110],
                    ],
                ],
            ],
        ]],
    ];

    $fixture['game']->update([
        'odds_data' => $oddsData,
        'odds_updated_at' => now()->subMinute(),
    ]);
    app(GameOddsSnapshotRecorder::class)->record(
        'nfl',
        $fixture['game'],
        ['id' => 'nfl-test-market', 'commence_time' => $fixture['event']->starts_at->toIso8601String()],
        $oddsData,
        Carbon::parse(now()->subMinute()),
        'test',
    );
}

function recordNflPredictionFeatureSnapshot(
    array $fixture,
    Prediction $prediction,
): PredictionFeatureSnapshot {
    return app(PredictionFeatureSnapshotRecorder::class)->record(
        $prediction,
        $fixture['game'],
        'nfl',
        [
            'predicted_spread' => $prediction->predicted_spread,
            'predicted_total' => $prediction->predicted_total,
            'win_probability' => $prediction->win_probability,
            'confidence_score' => $prediction->confidence_score,
            'model_version' => $prediction->model_version,
            'feature_version' => $prediction->feature_version,
            'blend_version' => $prediction->blend_version,
            'model_metadata' => $prediction->model_metadata,
        ],
        [
            'run_type' => 'pregame_prediction',
            'pregame_safe' => true,
            'availability_status' => 'observed_pregame',
            'generated_at' => now()->subSecond(),
            'features_available_at' => now()->subSecond(),
        ],
    );
}

it('freezes an NFL pregame quote in the canonical input and grades against it after live odds mutate', function () {
    $fixture = nflCanonicalMarketFixture();
    recordNflMarketSnapshot($fixture);
    app(NflCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinutes(2)->toImmutable());

    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']->fresh());
    $snapshot = $prediction->calculationRun->inputSnapshot;

    expect(data_get($snapshot->inputs, 'pregame_market.available'))->toBeTrue()
        ->and(data_get($snapshot->inputs, 'pregame_market.source'))->toBe('market_quotes')
        ->and((float) data_get($snapshot->inputs, 'pregame_market.consensus.spread.line'))->toBe(-3.0)
        ->and((float) data_get($snapshot->inputs, 'pregame_market.consensus.total.line'))->toBe(44.5);

    $fixture['game']->update([
        'odds_data' => [
            'home_team' => 'Chicago Bears',
            'away_team' => 'Detroit Lions',
            'bookmakers' => [[
                'key' => 'livebook',
                'markets' => [[
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'Chicago Bears', 'point' => -20, 'price' => -110],
                        ['name' => 'Detroit Lions', 'point' => 20, 'price' => -110],
                    ],
                ], [
                    'key' => 'totals',
                    'outcomes' => [
                        ['name' => 'Over', 'point' => 90.5, 'price' => -110],
                        ['name' => 'Under', 'point' => 90.5, 'price' => -110],
                    ],
                ]],
            ]],
        ],
        'odds_updated_at' => now()->addHours(4),
    ]);

    $this->travel(1)->day();
    $fixture['game']->update([
        'status' => 'STATUS_FINAL',
        'home_score' => 30,
        'away_score' => 20,
        'game_clock' => '0:00',
    ]);
    $this->artisan('nfl:evaluate-canonical-predictions', [
        '--game' => $fixture['game']->getKey(),
    ])->assertSuccessful();

    $evaluation = PredictionEvaluation::query()->sole();

    expect($evaluation->scoring_version)->toBe('canonical-nfl-market-v2')
        ->and((float) data_get($evaluation->market_comparison, 'pregame_market.spread.market_home_line'))->toBe(-3.0)
        ->and(data_get($evaluation->market_comparison, 'pregame_market.spread.result'))->toBe('win')
        ->and((float) data_get($evaluation->market_comparison, 'pregame_market.total.market_line'))->toBe(44.5)
        ->and(data_get($evaluation->market_comparison, 'pregame_market.total.result'))->toBe('win')
        ->and(data_get($evaluation->market_comparison, 'pregame_market.captured_at'))->toBe(
            data_get($snapshot->inputs, 'pregame_market.captured_at'),
        );
});

it('evaluates NFL ties without inventing a binary winner and retains spread and total grades', function () {
    $fixture = nflCanonicalMarketFixture();
    recordNflMarketSnapshot($fixture);
    app(NflCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinutes(2)->toImmutable());
    app(GenerateCanonicalPrediction::class)->execute($fixture['game']->fresh());
    $this->travel(1)->day();
    $fixture['game']->update(['status' => 'STATUS_FINAL', 'home_score' => 24, 'away_score' => 24, 'game_clock' => '0:00']);

    $this->artisan('nfl:evaluate-canonical-predictions', ['--game' => $fixture['game']->id])->assertSuccessful();
    $evaluation = PredictionEvaluation::query()->sole();
    expect(data_get($evaluation->actuals, 'winner'))->toBe('tie')
        ->and(data_get($evaluation->errors, 'winner_correct'))->toBeNull()
        ->and(data_get($evaluation->errors, 'brier_score'))->toBeNull()
        ->and(data_get($evaluation->errors, 'log_loss'))->toBeNull()
        ->and(data_get($evaluation->errors, 'spread_absolute_error'))->not->toBeNull()
        ->and(data_get($evaluation->market_comparison, 'pregame_market.spread.result'))->toBe('loss')
        ->and(data_get($evaluation->market_comparison, 'pregame_market.total.result'))->toBe('win');
    $this->artisan('nfl:evaluate-canonical-predictions', ['--game' => $fixture['game']->id])->assertSuccessful();
    expect(PredictionEvaluation::query()->count())->toBe(1);
});

it('covers regular and postseason games while excluding preseason from the production lifecycle', function () {
    $regular = nflCanonicalMarketFixture('2');
    $postseason = nflCanonicalMarketFixture('3');
    $preseason = nflCanonicalMarketFixture('1');
    recordNflMarketSnapshot($regular);
    recordNflLegacyEpaDisposition($regular);
    recordNflMarketSnapshot($postseason);
    recordNflLegacyEpaDisposition($postseason);
    app(NflCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());

    $this->artisan('nfl:generate-canonical-predictions', ['--season' => 2026])
        ->expectsOutputToContain('2 succeeded, 0 failed')
        ->assertSuccessful();

    $readiness = app(NflCanonicalCutoverReadinessService::class);
    $disabledReport = $readiness->report(2026);

    expect(CanonicalPrediction::query()->count())->toBe(2)
        ->and(CanonicalPrediction::query()->where('sport_event_id', $preseason['event']->getKey())->exists())->toBeFalse()
        ->and($disabledReport['eligible_event_count'])->toBe(2)
        ->and($disabledReport['slate_event_count'])->toBe(2)
        ->and($disabledReport['true_epa_applied_event_count'])->toBe(2)
        ->and($disabledReport['immutable_pregame_market_event_count'])->toBe(2)
        ->and($disabledReport['spread_quote_coverage_event_count'])->toBe(2)
        ->and($disabledReport['total_quote_coverage_event_count'])->toBe(2)
        ->and($disabledReport['data_ready_for_cutover'])->toBeTrue()
        ->and($disabledReport['ready_for_cutover'])->toBeFalse()
        ->and($disabledReport['canonical_pipeline_enabled'])->toBeFalse()
        ->and($disabledReport['readiness_blockers'])->toContain('canonical_pipeline_disabled')
        ->and($disabledReport['next_action'])->toContain('PREDICTION_LIFECYCLE_NFL_CANONICAL_PIPELINE=true')
        ->and($postseason['game']->fresh()->sport_event_id)->not->toBeNull();

    $playoffPrediction = CanonicalPrediction::query()->where('sport_event_id', $postseason['event']->id)->sole();
    expect(data_get($playoffPrediction->calculationRun->inputSnapshot->inputs, 'home.metrics.points_per_game'))->toBe(32);

    $this->artisan('nfl:report-canonical-cutover-readiness', [
        '--season' => 2026,
        '--json' => true,
        '--fail-on-not-ready' => true,
    ])
        ->expectsOutputToContain('canonical_pipeline_disabled')
        ->assertFailed();

    config()->set('prediction_lifecycle.canonical_pipeline.nfl', true);

    expect($readiness->report(2026)['ready_for_cutover'])->toBeTrue();
    $this->artisan('nfl:generate-canonical-predictions', [
        '--season' => 2026,
        '--days-forward' => 8,
        '--verify-readiness' => true,
    ])
        ->expectsOutputToContain('"ready_for_cutover": true')
        ->assertSuccessful();

    config()->set('nfl.predictions.true_epa.enabled', false);
    $epaDisabled = $readiness->report(2026);
    expect($epaDisabled['ready_for_cutover'])->toBeFalse()
        ->and($epaDisabled['configuration_blockers'])->toContain('true_epa_disabled')
        ->and($epaDisabled['next_action'])->toContain('NFL_TRUE_EPA_ENABLED=true');

    config()->set('nfl.predictions.true_epa.enabled', true);
    config()->set('nfl_research.enabled', false);
    $researchDisabled = $readiness->report(2026);
    expect($researchDisabled['ready_for_cutover'])->toBeFalse()
        ->and($researchDisabled['configuration_blockers'])->toContain('research_pipeline_disabled')
        ->and($researchDisabled['next_action'])->toContain('NFL_RESEARCH_PIPELINE_ENABLED=true');
});

it('verifies only the same bounded generation horizon while reporting historical gaps separately', function () {
    config()->set('prediction_lifecycle.canonical_pipeline.nfl', true);
    config()->set('nfl.predictions.true_epa.enabled', true);
    config()->set('nfl.predictions.true_epa.backfill_before_generation', true);
    config()->set('nfl_research.enabled', true);
    $inside = nflCanonicalMarketFixture();
    $outside = nflCanonicalMarketFixture();
    $outsideStart = now()->addDays(9);
    $outside['event']->update(['starts_at' => $outsideStart]);
    $outside['game']->update([
        'game_date' => $outsideStart,
        'game_time' => $outsideStart->format('H:i:s'),
    ]);
    recordNflMarketSnapshot($inside);
    recordNflLegacyEpaDisposition($inside);
    app(NflCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    app(GenerateCanonicalPrediction::class)->execute($inside['game']->fresh());

    $report = app(NflCanonicalCutoverReadinessService::class)->report(2026, daysForward: 8);

    expect($report['ready_for_cutover'])->toBeTrue()
        ->and($report['eligible_event_count'])->toBe(1)
        ->and($report['slate_event_count'])->toBe(1)
        ->and($report['safe_published_event_count'])->toBe(1)
        ->and($report['historical_eligible_event_count'])->toBe(2)
        ->and($report['historical_missing_safe_prediction_count'])->toBe(1)
        ->and($report['out_of_horizon_eligible_event_count'])->toBe(1)
        ->and(Carbon::parse($report['horizon_start_at'])->diffInDays(Carbon::parse($report['horizon_end_at'])))->toBe(8.0);

    $this->artisan('nfl:generate-canonical-predictions', [
        '--season' => 2026,
        '--days-forward' => 8,
        '--verify-readiness' => true,
    ])->assertSuccessful();
});

it('reports but blocks an explicit missing true EPA slate hold', function () {
    config()->set('prediction_lifecycle.canonical_pipeline.nfl', true);
    config()->set('nfl.predictions.true_epa.enabled', true);
    config()->set('nfl.predictions.true_epa.backfill_before_generation', true);
    config()->set('nfl_research.enabled', true);
    $fixture = nflCanonicalMarketFixture();
    recordNflMarketSnapshot($fixture);
    recordNflLegacyEpaDisposition($fixture, applied: false);
    app(NflCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    app(GenerateCanonicalPrediction::class)->execute($fixture['game']->fresh());

    $report = app(NflCanonicalCutoverReadinessService::class)->report(2026);

    expect($report['ready_for_cutover'])->toBeFalse()
        ->and($report['true_epa_applied_event_count'])->toBe(0)
        ->and($report['true_epa_explicit_hold_event_count'])->toBe(1)
        ->and($report['true_epa_disposition_event_count'])->toBe(1)
        ->and($report['missing_true_epa_disposition_game_ids'])->toBe([])
        ->and($report['coverage_blockers'])->toContain('true_epa_holds_present')
        ->and($report['next_action'])->toContain('Backfill true EPA')
        ->and($report['true_epa_authority'])->toBe('legacy_nfl_prediction_recommendation_layer')
        ->and($report['canonical_true_epa_role'])->toBe('not_applied_to_canonical_forecast');
});

it('blocks slate readiness when EPA has no applied or explicit hold disposition', function () {
    config()->set('prediction_lifecycle.canonical_pipeline.nfl', true);
    config()->set('nfl.predictions.true_epa.enabled', true);
    config()->set('nfl.predictions.true_epa.backfill_before_generation', true);
    config()->set('nfl_research.enabled', true);
    $fixture = nflCanonicalMarketFixture();
    recordNflMarketSnapshot($fixture);
    app(NflCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    app(GenerateCanonicalPrediction::class)->execute($fixture['game']->fresh());

    $report = app(NflCanonicalCutoverReadinessService::class)->report(2026);

    expect($report['ready_for_cutover'])->toBeFalse()
        ->and($report['coverage_blockers'])->toContain('missing_true_epa_disposition')
        ->and($report['missing_true_epa_disposition_game_ids'])->toBe([$fixture['game']->getKey()]);
});

it('keeps a valid immutable market fresh relative to canonical capture time', function () {
    config()->set('prediction_lifecycle.canonical_pipeline.nfl', true);
    config()->set('nfl.predictions.true_epa.enabled', true);
    config()->set('nfl.predictions.true_epa.backfill_before_generation', true);
    config()->set('nfl.predictions.pregame_market.maximum_quote_age_minutes', 30);
    config()->set('nfl_research.enabled', true);
    $fixture = nflCanonicalMarketFixture();
    recordNflMarketSnapshot($fixture);
    recordNflLegacyEpaDisposition($fixture);
    app(NflCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinutes(2)->toImmutable());
    app(GenerateCanonicalPrediction::class)->execute($fixture['game']->fresh());

    $this->travel(31)->minutes();
    $report = app(NflCanonicalCutoverReadinessService::class)->report(2026);

    expect($report['ready_for_cutover'])->toBeTrue()
        ->and($report['immutable_pregame_market_event_count'])->toBe(1)
        ->and($report['spread_quote_coverage_event_count'])->toBe(1)
        ->and($report['total_quote_coverage_event_count'])->toBe(1);
});

it('prefers provider market observation time over a fresh ingestion timestamp', function () {
    config()->set('nfl.predictions.pregame_market.maximum_quote_age_minutes', 30);
    $fixture = nflCanonicalMarketFixture();
    recordNflMarketSnapshot(
        $fixture,
        providerObservedAt: now()->subHours(2)->toIso8601String(),
    );
    app(NflCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinutes(2)->toImmutable());

    expect(data_get(MarketQuote::query()->firstOrFail()->metadata, 'provider_observed_at'))
        ->toBe(now()->subHours(2)->toIso8601String())
        ->and(fn () => app(GenerateCanonicalPrediction::class)->execute($fixture['game']->fresh()))
        ->toThrow(PredictionLifecycleException::class, 'fresh two-sided spread and total quotes');
});

it('fails canonical generation and released decisions for stale or one-sided market coverage', function () {
    config()->set('nfl.predictions.pregame_market.maximum_quote_age_minutes', 30);
    $fixture = nflCanonicalMarketFixture();
    recordNflMarketSnapshot($fixture);
    MarketQuote::query()
        ->where('game_id', $fixture['game']->getKey())
        ->where('market_key', 'spreads')
        ->where('side', 'away')
        ->delete();
    app(NflCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinutes(2)->toImmutable());

    expect(fn () => app(GenerateCanonicalPrediction::class)->execute($fixture['game']->fresh()))
        ->toThrow(PredictionLifecycleException::class, 'fresh two-sided spread and total quotes');

    $legacy = Prediction::factory()->create([
        'game_id' => $fixture['game']->getKey(),
        'predicted_spread' => 8.0,
        'predicted_total' => 45.0,
        'win_probability' => 0.72,
        'confidence_score' => 72,
        'model_metadata' => [
            'analysis_layer' => [
                'applied' => true,
                'bet_classification' => 'bet',
                'eligibility' => ['eligible' => true],
                'calculated_edge' => ['market_spread' => 3.0, 'spread_points' => 5.0],
                'pro_signal_layer' => [
                    'tier' => 'official_candidate',
                    'recommended_markets' => [[
                        'market' => 'spread',
                        'score' => 82,
                        'tier' => 'official_candidate',
                    ]],
                ],
            ],
        ],
    ])->load('game.homeTeam', 'game.awayTeam', 'game.sportEvent');
    recordNflPredictionFeatureSnapshot($fixture, $legacy);

    $held = app(NflReleasedBetDecisionRecorder::class)->record($legacy)->sole();
    expect($held->status)->toBe('held_candidate')
        ->and($held->is_bet)->toBeFalse()
        ->and(data_get($held->explanation, 'hold_reason'))->toBe('exact_fresh_paired_quote_missing');

    recordNflMarketSnapshot($fixture, total: 45.0);
    $this->travel(31)->minutes();
    expect(app(NflReleasedBetDecisionRecorder::class)->record($legacy->fresh())->sole()->is($held))->toBeTrue();
});

it('preserves old odds observations and records fresh unchanged NFL markets after the refresh interval', function () {
    $fixture = nflCanonicalMarketFixture();
    recordNflMarketSnapshot($fixture);
    $first = GameOddsSnapshot::query()->sole();
    $originalCapturedAt = $first->captured_at->toIso8601String();
    $this->travel(61)->minutes();
    recordNflMarketSnapshot($fixture);

    expect(GameOddsSnapshot::query()->count())->toBe(2)
        ->and($first->fresh()->captured_at->toIso8601String())->toBe($originalCapturedAt);
    app(NflCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']->fresh());
    expect(data_get($prediction->calculationRun->inputSnapshot->inputs, 'pregame_market.game_odds_snapshot_id'))
        ->not->toBe($first->id);
});

it('records immutable no-bet forecasts and settles their W-L without counting wager profit', function () {
    $fixture = nflCanonicalMarketFixture();
    recordNflMarketSnapshot($fixture);
    $prediction = Prediction::factory()->create([
        'game_id' => $fixture['game']->id, 'predicted_spread' => 8, 'predicted_total' => 48,
        'win_probability' => 0.7,
        'model_metadata' => ['analysis_layer' => [
            'applied' => true, 'bet_classification' => 'pass',
            'eligibility' => ['eligible' => false, 'model_reasons' => ['edge_not_sufficient']],
        ]],
    ]);
    $snapshot = recordNflPredictionFeatureSnapshot($fixture, $prediction);
    $recorder = app(NflPredictionDispositionRecorder::class);
    $decisions = $recorder->record($prediction);
    $recorder->record($prediction);
    expect($decisions)->toHaveCount(3)
        ->and(BetDecision::query()->count())->toBe(3)
        ->and($decisions->pluck('status')->unique()->all())->toBe(['model_no_bet'])
        ->and($decisions->pluck('prediction_feature_snapshot_id')->unique()->all())->toBe([$snapshot->id])
        ->and($decisions->where('is_bet', true))->toHaveCount(0);
    $fixture['game']->update(['status' => 'STATUS_FINAL', 'home_score' => 27, 'away_score' => 20]);
    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'nfl'])->assertSuccessful();
    expect(BetSettlement::query()->count())->toBe(3)
        ->and((float) BetSettlement::query()->sum('profit_units'))->toBe(0.0);
});

it('records data holds without fabricating market selections or settlements', function () {
    $fixture = nflCanonicalMarketFixture();
    $prediction = recordNflLegacyEpaDisposition($fixture, false);
    recordNflPredictionFeatureSnapshot($fixture, $prediction);
    $decisions = app(NflPredictionDispositionRecorder::class)->record($prediction);
    expect($decisions)->toHaveCount(3)
        ->and($decisions->pluck('status')->unique()->all())->toBe(['model_hold'])
        ->and($decisions->first()->eligibility_reasons)->toContain('missing_true_epa', 'fresh_paired_market_or_forecast_missing')
        ->and($decisions->where('is_bet', true))->toHaveCount(0);
    $fixture['game']->update(['status' => 'STATUS_FINAL', 'home_score' => 27, 'away_score' => 20]);
    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'nfl'])->assertSuccessful();
    expect(BetSettlement::query()->count())->toBe(0);
});

it('excludes official candidate markets from additional no-bet dispositions', function () {
    $fixture = nflCanonicalMarketFixture();
    recordNflMarketSnapshot($fixture);
    $prediction = recordNflLegacyEpaDisposition($fixture);
    recordNflPredictionFeatureSnapshot($fixture, $prediction);
    $decisions = app(NflPredictionDispositionRecorder::class)->record($prediction, ['spreads']);
    expect($decisions->pluck('market_key')->all())->toBe(['h2h', 'totals']);
});

it('blocks readiness when any current forecast market is missing its immutable disposition', function () {
    config()->set('prediction_lifecycle.canonical_pipeline.nfl', true);
    config()->set('nfl.predictions.true_epa.enabled', true);
    config()->set('nfl.predictions.true_epa.backfill_before_generation', true);
    config()->set('nfl_research.enabled', true);
    $fixture = nflCanonicalMarketFixture();
    recordNflMarketSnapshot($fixture);
    recordNflLegacyEpaDisposition($fixture);
    app(NflCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    app(GenerateCanonicalPrediction::class)->execute($fixture['game']);
    $readiness = app(NflCanonicalCutoverReadinessService::class);
    expect($readiness->report(2026)['ready_for_cutover'])->toBeTrue();

    BetDecision::query()->where('game_id', $fixture['game']->id)->where('market_key', 'totals')->delete();
    $report = $readiness->report(2026);
    expect($report['ready_for_cutover'])->toBeFalse()
        ->and($report['coverage_blockers'])->toContain('missing_market_dispositions')
        ->and($report['recorded_disposition_event_count'])->toBe(0)
        ->and($report['missing_disposition_game_ids'])->toBe([$fixture['game']->id]);
});

it('fails readiness when formerly safe forecasts have stopped refreshing', function () {
    config()->set('prediction_lifecycle.canonical_pipeline.nfl', true);
    config()->set('nfl.predictions.true_epa.enabled', true);
    config()->set('nfl.predictions.true_epa.backfill_before_generation', true);
    config()->set('nfl_research.enabled', true);
    $fixture = nflCanonicalMarketFixture();
    recordNflMarketSnapshot($fixture);
    recordNflLegacyEpaDisposition($fixture);
    app(NflCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    app(GenerateCanonicalPrediction::class)->execute($fixture['game']);
    $readiness = app(NflCanonicalCutoverReadinessService::class);

    expect($readiness->report(2026)['ready_for_cutover'])->toBeTrue();
    $this->travel(361)->minutes();
    $report = $readiness->report(2026);
    expect($report['ready_for_cutover'])->toBeFalse()
        ->and($report['stale_prediction_game_ids'])->toBe([$fixture['game']->id])
        ->and($report['readiness_blockers'])->toContain('stale_pregame_predictions');
});

it('filters published revisions by season type without a season or week filter', function () {
    $regular = nflCanonicalMarketFixture('2');
    $postseason = nflCanonicalMarketFixture('3');
    recordNflMarketSnapshot($regular);
    recordNflMarketSnapshot($postseason);
    app(NflCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    app(GenerateCanonicalPrediction::class)->execute($regular['game']);
    app(GenerateCanonicalPrediction::class)->execute($postseason['game']);

    $report = app(CanonicalSportCutoverReadinessService::class)->report(
        'nfl',
        seasonTypes: ['2'],
    );

    expect($report['ready_for_cutover'])->toBeTrue()
        ->and($report['eligible_event_count'])->toBe(1)
        ->and($report['published_revision_count'])->toBe(1)
        ->and($report['unsafe_published_revision_count'])->toBe(0);
});

it('atomically replaces the active NFL release before v2 generation', function () {
    $fixture = nflCanonicalMarketFixture();
    recordNflMarketSnapshot($fixture);
    $old = app(NflCalculationReleaseRegistrar::class)->register(
        semanticVersion: '1.0.0',
        effectiveAt: now()->subMinutes(2)->toImmutable(),
    );
    $effectiveAt = now()->subMinute()->toImmutable();

    $this->artisan('nfl:register-calculation-release', [
        '--replace-active' => true,
        '--effective-at' => $effectiveAt->toIso8601String(),
        '--actor' => 'test-suite',
        '--reason' => 'Test atomic NFL release replacement.',
    ])
        ->expectsOutput('NFL calculation release 1.1.0 is approved.')
        ->assertSuccessful();
    $this->artisan('nfl:register-calculation-release', [
        '--replace-active' => true,
        '--effective-at' => $effectiveAt->toIso8601String(),
        '--actor' => 'test-suite',
        '--reason' => 'Test idempotent NFL release replacement.',
    ])->assertSuccessful();

    $replacement = CalculationRelease::query()
        ->where('sport', 'nfl')
        ->where('semantic_version', '1.1.0')
        ->sole();
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']->fresh());
    $report = app(NflCanonicalCutoverReadinessService::class)->report(2026);

    expect($old->fresh()->status)->toBe('retired')
        ->and($old->fresh()->retired_at?->toDateTimeString())->toBe($effectiveAt->toDateTimeString())
        ->and($replacement->status)->toBe('approved')
        ->and($replacement->input_schema_version)->toBe('nfl-pregame-v2')
        ->and(CalculationRelease::query()->where('sport', 'nfl')->where('status', 'approved')->count())->toBe(1)
        ->and($prediction->model_version)->toBe('1.1.0')
        ->and($prediction->feature_version)->toBe('nfl-pregame-v2')
        ->and($report['cutover_release_version'])->toBe('1.1.0')
        ->and(Carbon::parse($report['cutover_started_at'])->toDateTimeString())->toBe($effectiveAt->toDateTimeString());
});

it('refuses NFL canonical cutover when effective configuration has no row coverage', function () {
    config()->set('prediction_lifecycle.canonical_pipeline.nfl', true);
    config()->set('nfl.predictions.true_epa.enabled', true);
    config()->set('nfl.predictions.true_epa.backfill_before_generation', true);
    config()->set('nfl_research.enabled', true);
    app(NflCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());

    $report = app(NflCanonicalCutoverReadinessService::class)->report(2026);

    expect($report['ready_for_cutover'])->toBeFalse()
        ->and($report['coverage_blockers'])->toContain(
            'no_eligible_regular_season_events',
            'zero_safe_canonical_predictions',
        )
        ->and($report['next_action'])->toContain('regular-season NFL event identity');
});

it('records and settles only a released NFL bet with an exact immutable pregame quote', function () {
    $fixture = nflCanonicalMarketFixture();
    recordNflMarketSnapshot($fixture);
    $prediction = Prediction::factory()->create([
        'game_id' => $fixture['game']->getKey(),
        'predicted_spread' => 8.0,
        'predicted_total' => 45.0,
        'win_probability' => 0.72,
        'confidence_score' => 72,
        'model_metadata' => [
            'analysis_layer' => [
                'applied' => true,
                'bet_classification' => 'bet',
                'eligibility' => ['eligible' => true],
                'calculated_edge' => [
                    'market_spread' => 3.0,
                    'spread_points' => 5.0,
                    'market_total' => 44.5,
                    'total_points' => 0.5,
                ],
                'reason_codes' => ['spread_market_edge'],
                'risk_flags' => [],
                'pro_signal_layer' => [
                    'tier' => 'official_candidate',
                    'recommended_markets' => [[
                        'market' => 'spread',
                        'score' => 82,
                        'tier' => 'official_candidate',
                    ]],
                    'market_context' => ['spread_price' => -110],
                ],
            ],
        ],
    ])->load('game.homeTeam', 'game.awayTeam', 'game.sportEvent');
    $featureSnapshot = recordNflPredictionFeatureSnapshot($fixture, $prediction);
    $decisions = app(NflReleasedBetDecisionRecorder::class)->record($prediction);
    app(NflReleasedBetDecisionRecorder::class)->record($prediction);
    $decision = $decisions->sole();

    $flippedMetadata = $prediction->model_metadata;
    data_set($flippedMetadata, 'analysis_layer.calculated_edge.spread_points', -5.0);
    $prediction->update(['model_metadata' => $flippedMetadata]);
    $flippedDecision = app(NflReleasedBetDecisionRecorder::class)
        ->record($prediction->fresh())
        ->sole();

    expect($decision)->not->toBeNull()
        ->and(BetDecision::query()->count())->toBe(1)
        ->and($flippedDecision->is($decision))->toBeTrue()
        ->and($decision->is_bet)->toBeTrue()
        ->and($decision->is_public)->toBeFalse()
        ->and($decision->is_tracking_only)->toBeTrue()
        ->and($decision->pregame_safe)->toBeTrue()
        ->and($decision->prediction_feature_snapshot_id)->toBe($featureSnapshot->getKey())
        ->and($decision->model_run_id)->toBe($featureSnapshot->model_run_id)
        ->and((float) $decision->line)->toBe(-3.0)
        ->and(data_get($decision->market_snapshot, 'immutable_entry_quote'))->toBeTrue();

    $this->travel(1)->day();
    $fixture['game']->update([
        'status' => 'STATUS_FINAL',
        'home_score' => 27,
        'away_score' => 20,
    ]);
    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'nfl'])
        ->expectsOutput('Settled 1 decision(s).')
        ->assertSuccessful();

    expect(BetSettlement::query()->sole()->result_status)->toBe('win')
        ->and((float) BetSettlement::query()->sole()->profit_units)->toBeGreaterThan(0);
});

it('persists and reports an explicit hold until every official candidate market has an exact quote', function () {
    config()->set('prediction_lifecycle.canonical_pipeline.nfl', true);
    config()->set('nfl.predictions.true_epa.enabled', true);
    config()->set('nfl.predictions.true_epa.backfill_before_generation', true);
    config()->set('nfl_research.enabled', true);
    $fixture = nflCanonicalMarketFixture();
    recordNflMarketSnapshot($fixture, homeLine: -3.0);
    $prediction = Prediction::factory()->create([
        'game_id' => $fixture['game']->getKey(),
        'predicted_spread' => 8.0,
        'predicted_total' => 45.0,
        'win_probability' => 0.72,
        'confidence_score' => 72,
        'model_metadata' => [
            'true_epa' => ['enabled' => true, 'applied' => true, 'reason' => 'applied'],
            'analysis_layer' => [
                'applied' => true,
                'bet_classification' => 'bet',
                'eligibility' => ['status' => 'candidate', 'eligible' => true, 'data_reasons' => []],
                'calculated_edge' => ['market_spread' => 4.0, 'spread_points' => 4.0],
                'reason_codes' => ['spread_market_edge'],
                'risk_flags' => [],
                'pro_signal_layer' => [
                    'tier' => 'official_candidate',
                    'recommended_markets' => [[
                        'market' => 'spread',
                        'score' => 82,
                        'tier' => 'official_candidate',
                    ]],
                ],
            ],
        ],
    ])->load('game.homeTeam', 'game.awayTeam', 'game.sportEvent');
    recordNflPredictionFeatureSnapshot($fixture, $prediction);
    app(NflCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    app(GenerateCanonicalPrediction::class)->execute($fixture['game']->fresh());

    $coverage = app(NflReleasedBetDecisionRecorder::class)->recordWithCoverage($prediction);
    $held = $coverage['decisions']->sole();
    $blocked = app(NflCanonicalCutoverReadinessService::class)->report(2026, daysForward: 8);

    expect($coverage['missing_market_keys'])->toBe(['spreads'])
        ->and($held->status)->toBe('held_candidate')
        ->and($held->is_bet)->toBeFalse()
        ->and($blocked['ready_for_cutover'])->toBeFalse()
        ->and($blocked['official_candidate_market_count'])->toBe(1)
        ->and($blocked['released_candidate_decision_count'])->toBe(0)
        ->and($blocked['missing_released_candidate_decision_game_ids'])->toBe([$fixture['game']->getKey()])
        ->and($blocked['candidate_decision_hold_reasons_by_game'][$fixture['game']->getKey()]['spreads'])
        ->toBe('exact_fresh_paired_quote_missing')
        ->and($blocked['coverage_blockers'])->toContain('missing_released_candidate_decisions');

    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'nfl'])
        ->expectsOutput('Settled 0 decision(s).')
        ->assertSuccessful();
    expect(BetSettlement::query()->count())->toBe(0);

    recordNflMarketSnapshot($fixture, homeLine: -4.0);
    $released = app(NflReleasedBetDecisionRecorder::class)
        ->recordWithCoverage($prediction->fresh());
    app(NflPredictionDispositionRecorder::class)->record($prediction->fresh(), $released['candidate_market_keys']);
    $ready = app(NflCanonicalCutoverReadinessService::class)->report(2026, daysForward: 8);

    expect($released['missing_market_keys'])->toBe([])
        ->and($released['decisions']->sole()->status)->toBe('released_tracking_bet')
        ->and($ready['released_candidate_decision_count'])->toBe(1)
        ->and($ready['missing_released_candidate_decision_game_ids'])->toBe([])
        ->and($ready['ready_for_cutover'])->toBeTrue();
});

it('creates no NFL bet decision for a lean or for an unmatched market line', function (string $classification, float $selectionLine) {
    $fixture = nflCanonicalMarketFixture();
    recordNflMarketSnapshot($fixture, homeLine: -3.0);
    $prediction = Prediction::factory()->create([
        'game_id' => $fixture['game']->getKey(),
        'predicted_spread' => 8.0,
        'predicted_total' => 45.0,
        'win_probability' => 0.72,
        'confidence_score' => 72,
        'model_metadata' => [
            'analysis_layer' => [
                'applied' => true,
                'bet_classification' => $classification,
                'eligibility' => ['eligible' => true],
                'calculated_edge' => [
                    'market_spread' => -$selectionLine,
                    'spread_points' => 5.0,
                    'market_total' => 44.5,
                    'total_points' => 0.5,
                ],
                'reason_codes' => [],
                'risk_flags' => [],
                'pro_signal_layer' => [
                    'tier' => 'official_candidate',
                    'recommended_markets' => [[
                        'market' => 'spread',
                        'score' => 82,
                        'tier' => 'official_candidate',
                    ]],
                    'market_context' => ['spread_price' => -110],
                ],
            ],
        ],
    ])->load('game.homeTeam', 'game.awayTeam', 'game.sportEvent');
    recordNflPredictionFeatureSnapshot($fixture, $prediction);

    $decisions = app(NflReleasedBetDecisionRecorder::class)->record($prediction);
    if ($classification === 'lean') {
        expect($decisions)->toBeEmpty()
            ->and(BetDecision::query()->count())->toBe(0);
    } else {
        expect($decisions)->toHaveCount(1)
            ->and($decisions->sole()->status)->toBe('held_candidate')
            ->and($decisions->sole()->is_bet)->toBeFalse()
            ->and(data_get($decisions->sole()->explanation, 'hold_reason'))
            ->toBe('exact_fresh_paired_quote_missing');
    }
})->with([
    'lean is not a wager' => ['lean', -3.0],
    'released line has no exact quote' => ['bet', -4.0],
]);
