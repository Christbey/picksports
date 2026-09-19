<?php

use App\Actions\CFB\CalculateElo;
use App\Actions\CFB\GenerateCanonicalPrediction;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CFB\FpiRating;
use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Models\EventInputSnapshot;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\PredictionEvaluation;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\SportEventResult;
use App\Models\User;
use App\Services\CFB\CfbPlayerEvidenceService;
use App\Services\CFB\Predictions\CfbCalculationReleaseRegistrar;
use App\Services\CFB\Predictions\CfbCanonicalCutoverReadinessService;
use App\Services\NFL\NflPredictionDispositionRecorder;
use App\Services\NFL\Predictions\NflCalculationReleaseRegistrar;
use App\Services\NFL\Predictions\NflCanonicalCutoverReadinessService;
use App\Services\OddsApi\GameOddsSnapshotRecorder;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

dataset('canonical football sports', [
    'CFB' => [[
        'sport' => 'cfb', 'game' => Game::class, 'team' => Team::class,
        'metric' => TeamMetric::class, 'legacy_prediction' => Prediction::class,
        'generator' => GenerateCanonicalPrediction::class, 'registrar' => CfbCalculationReleaseRegistrar::class,
        'readiness' => CfbCanonicalCutoverReadinessService::class, 'team_names' => ['school' => 'University', 'mascot' => 'Hawks'],
        'metric_season_type' => false,
    ]],
    'NFL' => [[
        'sport' => 'nfl', 'game' => App\Models\NFL\Game::class, 'team' => App\Models\NFL\Team::class,
        'metric' => App\Models\NFL\TeamMetric::class, 'legacy_prediction' => App\Models\NFL\Prediction::class,
        'generator' => App\Actions\NFL\GenerateCanonicalPrediction::class, 'registrar' => NflCalculationReleaseRegistrar::class,
        'readiness' => NflCanonicalCutoverReadinessService::class, 'team_names' => ['location' => 'Chicago', 'name' => 'Bears'],
        'metric_season_type' => true,
    ]],
]);

/** @param array<string,mixed> $definition @return array<string,mixed> */
function canonicalFootballFixture(array $definition): array
{
    $startsAt = now()->addDay()->startOfHour();
    $event = SportEvent::factory()->create([
        'sport' => $definition['sport'], 'season' => 2026, 'season_type' => 'regular',
        'week' => 1, 'starts_at' => $startsAt, 'status' => 'STATUS_SCHEDULED', 'neutral_site' => false,
    ]);
    $teamClass = $definition['team'];
    $home = $teamClass::factory()->create([
        ...$definition['team_names'],
        'abbreviation' => $definition['home_abbreviation'] ?? 'HOM',
        'elo_rating' => 1620,
    ]);
    $awayNames = $definition['sport'] === 'cfb' ? ['school' => 'College', 'mascot' => 'Bears'] : ['location' => 'Detroit', 'name' => 'Lions'];
    $away = $teamClass::factory()->create([
        ...$awayNames,
        'abbreviation' => $definition['away_abbreviation'] ?? 'AWY',
        'elo_rating' => 1440,
    ]);
    if ($definition['sport'] === 'cfb') {
        foreach ([$home, $away] as $team) {
            DB::table('cfb_elo_season_initializations')->insert([
                'team_id' => $team->id, 'season' => 2026, 'model_version' => CalculateElo::MODEL_VERSION,
                'prior_rating' => $team->elo_rating, 'initial_rating' => $team->elo_rating,
                'regression_factor' => 0.3, 'active_slot' => 1,
                'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
            ]);
        }
    }
    $metricClass = $definition['metric'];

    foreach ([[$home, 32.0, 18.0, 14.0, 2.0], [$away, 19.0, 29.0, -10.0, -1.0]] as [$team, $scored, $allowed, $net, $turnovers]) {
        $attributes = [
            'team_id' => $team->getKey(), 'season' => 2026, 'wins' => 9, 'losses' => 3,
            'offensive_rating' => 110, 'defensive_rating' => 95, 'net_rating' => $net,
            'points_per_game' => $scored, 'points_allowed_per_game' => $allowed,
            'turnover_differential' => $turnovers, 'strength_of_schedule' => 0,
            'recent_form_rating' => $net / 2, 'injury_adjusted_team_rating' => $team->elo_rating,
            'injury_total_adjustment' => 0, 'rest_travel_fatigue' => 0, 'calculation_date' => now()->toDateString(),
        ];
        if ($definition['metric_season_type']) {
            $attributes['season_type'] = 'regular';
            $attributes['predictive_rating'] = $net;
            $attributes['offensive_true_epa_per_play'] = $team->is($home) ? 0.12 : 0.04;
            $attributes['defensive_true_epa_per_play'] = $team->is($home) ? -0.02 : 0.01;
            $attributes['net_true_epa_per_play'] = $team->is($home) ? 0.14 : 0.03;
        } else {
            $attributes['power_rating'] = $net;
            $attributes['fpi'] = $net;
        }
        $metricClass::query()->create($attributes);
    }

    $gameClass = $definition['game'];
    $game = $gameClass::factory()->create([
        'sport_event_id' => $event->getKey(), 'home_team_id' => $home->getKey(), 'away_team_id' => $away->getKey(),
        'season' => 2026, 'season_type' => 'regular', 'week' => 1, 'status' => 'STATUS_SCHEDULED', 'game_date' => $startsAt,
        'home_score' => null, 'away_score' => null, 'neutral_site' => false,
    ]);

    $fixture = compact('event', 'game', 'home', 'away');
    if ($definition['sport'] === 'nfl') {
        recordCanonicalFootballNflMarket($fixture);
    }

    return $fixture;
}

/** @param array<string,mixed> $fixture */
function recordCanonicalFootballNflMarket(array $fixture): void
{
    $oddsData = [
        'home_team' => 'Chicago Bears',
        'away_team' => 'Detroit Lions',
        'bookmakers' => [[
            'key' => 'testbook',
            'title' => 'Test Book',
            'last_update' => now()->subMinute()->toIso8601String(),
            'markets' => [[
                'key' => 'spreads',
                'outcomes' => [
                    ['name' => 'Chicago Bears', 'point' => -3.0, 'price' => -110],
                    ['name' => 'Detroit Lions', 'point' => 3.0, 'price' => -110],
                ],
            ], [
                'key' => 'totals',
                'outcomes' => [
                    ['name' => 'Over', 'point' => 44.5, 'price' => -110],
                    ['name' => 'Under', 'point' => 44.5, 'price' => -110],
                ],
            ]],
        ]],
    ];
    app(GameOddsSnapshotRecorder::class)->record(
        'nfl',
        $fixture['game'],
        ['id' => 'nfl-readiness-fixture', 'commence_time' => $fixture['event']->starts_at->toIso8601String()],
        $oddsData,
        Carbon::parse(now()->subMinute()),
        'test',
    );
}

/** @param array<string,mixed> $fixture */
function prepareNflCanonicalReadinessFixture(array $fixture): void
{
    recordCanonicalFootballNflMarket($fixture);
    $prediction = App\Models\NFL\Prediction::factory()->create([
        'game_id' => $fixture['game']->getKey(),
        'model_metadata' => [
            'true_epa' => ['enabled' => true, 'applied' => true, 'reason' => 'applied'],
            'analysis_layer' => [
                'applied' => true,
                'eligibility' => ['status' => 'candidate', 'eligible' => true, 'data_reasons' => []],
            ],
        ],
    ]);
    app(PredictionFeatureSnapshotRecorder::class)->record(
        $prediction,
        $fixture['game'],
        'nfl',
        $prediction->only(['predicted_spread', 'predicted_total', 'win_probability', 'confidence_score', 'model_metadata']),
        [
            'run_type' => 'pregame_prediction',
            'pregame_safe' => true,
            'availability_status' => 'observed_pregame',
            'generated_at' => now()->subSecond(),
            'features_available_at' => now()->subSecond(),
        ],
    );
    app(NflPredictionDispositionRecorder::class)->record($prediction);
}

it('generates reproducible canonical football predictions without legacy writes', function (array $definition) {
    $fixture = canonicalFootballFixture($definition);
    $release = app($definition['registrar'])->register(effectiveAt: now()->subMinute()->toImmutable());
    config()->set("{$definition['sport']}.elo.home_field_advantage", -1000);
    $prediction = app($definition['generator'])->execute($fixture['game']);
    $moneyline = $prediction->markets->where('market_type', 'moneyline')->where('selection', 'home')->sole();
    $spread = $prediction->markets->where('market_type', 'spread')->where('selection', 'home')->sole();
    $legacy = $definition['legacy_prediction'];

    expect($prediction->sport)->toBe($definition['sport'])
        ->and($prediction->calculationRun->release->is($release))->toBeTrue()
        ->and($prediction->calculationRun->inputSnapshot->pregame_safety_status)->toBe('verified')
        ->and(data_get($prediction->calculationRun->inputSnapshot->inputs, 'away.metrics.points_per_game'))->toBe(19)
        ->and((float) $moneyline->probability)->toBeGreaterThan(0.5)
        ->and((float) $spread->projected_line)->toBeLessThan(0)
        ->and($legacy::query()->count())->toBe(0);
})->with('canonical football sports');

it('uses prior-season football metrics when current-season rows are empty shells', function () {
    $definition = [
        'sport' => 'cfb', 'game' => Game::class, 'team' => Team::class,
        'metric' => TeamMetric::class, 'legacy_prediction' => Prediction::class,
        'generator' => GenerateCanonicalPrediction::class, 'registrar' => CfbCalculationReleaseRegistrar::class,
        'readiness' => CfbCanonicalCutoverReadinessService::class, 'team_names' => ['school' => 'University', 'mascot' => 'Hawks'],
        'metric_season_type' => false,
    ];
    $fixture = canonicalFootballFixture($definition);

    foreach ([
        [$fixture['home'], 30.0, 20.0],
        [$fixture['away'], 24.0, 28.0],
    ] as [$team, $scored, $allowed]) {
        $current = TeamMetric::query()->where('team_id', $team->getKey())->where('season', 2026)->sole();
        $current->update([
            'wins' => 0,
            'losses' => 0,
            'points_per_game' => 0,
            'points_allowed_per_game' => 0,
        ]);
        $previous = $current->replicate();
        $previous->fill([
            'season' => 2025,
            'wins' => 8,
            'losses' => 4,
            'points_per_game' => $scored,
            'points_allowed_per_game' => $allowed,
            'recent_form_rating' => 0,
            'rest_travel_fatigue' => 0,
            'calculation_date' => '2025-12-31',
        ])->save();
    }

    app(CfbCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']);
    $total = $prediction->markets->where('market_type', 'total')->where('selection', 'combined')->sole();

    expect(data_get($prediction->calculationRun->inputSnapshot->inputs, 'home.metrics.record_season'))->toBe(2025)
        ->and(data_get($prediction->calculationRun->inputSnapshot->inputs, 'away.metrics.record_season'))->toBe(2025)
        ->and((float) $total->projected_line)->toBe(52.0);
});

it('uses the football scoring baseline when no team has a completed metric sample', function () {
    $definition = [
        'sport' => 'cfb', 'game' => Game::class, 'team' => Team::class,
        'metric' => TeamMetric::class, 'legacy_prediction' => Prediction::class,
        'generator' => GenerateCanonicalPrediction::class, 'registrar' => CfbCalculationReleaseRegistrar::class,
        'readiness' => CfbCanonicalCutoverReadinessService::class, 'team_names' => ['school' => 'University', 'mascot' => 'Hawks'],
        'metric_season_type' => false,
    ];
    $fixture = canonicalFootballFixture($definition);
    TeamMetric::query()->update([
        'wins' => 0,
        'losses' => 0,
        'points_per_game' => 0,
        'points_allowed_per_game' => 0,
    ]);

    app(CfbCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']);
    $total = $prediction->markets->where('market_type', 'total')->where('selection', 'combined')->sole();

    expect((float) $total->projected_line)->toBe(56.0);
});

it('retains preseason CFB power ratings before either team has a completed-game sample', function () {
    $definition = [
        'sport' => 'cfb', 'game' => Game::class, 'team' => Team::class,
        'metric' => TeamMetric::class, 'legacy_prediction' => Prediction::class,
        'generator' => GenerateCanonicalPrediction::class, 'registrar' => CfbCalculationReleaseRegistrar::class,
        'readiness' => CfbCanonicalCutoverReadinessService::class, 'team_names' => ['school' => 'University', 'mascot' => 'Hawks'],
        'metric_season_type' => false,
    ];
    $fixture = canonicalFootballFixture($definition);
    $fixture['home']->update(['elo_rating' => 1500]);
    $fixture['away']->update(['elo_rating' => 1500]);
    TeamMetric::query()->where('team_id', $fixture['home']->getKey())->update([
        'wins' => 0,
        'losses' => 0,
        'power_rating' => 14,
        'fpi' => 14,
    ]);
    TeamMetric::query()->where('team_id', $fixture['away']->getKey())->update([
        'wins' => 0,
        'losses' => 0,
        'power_rating' => -10,
        'fpi' => -10,
    ]);

    FpiRating::factory()->create(['team_id' => $fixture['home']->id, 'season' => 2026, 'week' => 0, 'fpi' => 14]);
    FpiRating::factory()->create(['team_id' => $fixture['away']->id, 'season' => 2026, 'week' => 0, 'fpi' => -10]);

    FpiRating::factory()->create(['team_id' => $fixture['home']->id, 'season' => 2026, 'week' => 1, 'fpi' => 99, 'updated_at' => now()->addDay()]);
    FpiRating::factory()->create(['team_id' => $fixture['away']->id, 'season' => 2026, 'week' => 5, 'fpi' => 99]);

    app(CfbCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']);
    $spread = $prediction->markets
        ->where('market_type', 'spread')
        ->where('selection', 'home')
        ->sole();

    expect(data_get($prediction->calculationRun->inputSnapshot->inputs, 'home.metrics.fpi'))->toBe(14)
        ->and(data_get($prediction->calculationRun->inputSnapshot->inputs, 'away.metrics.fpi'))->toBe(-10)
        ->and((float) $spread->projected_line)->toBe(-23.6);
});

it('can generate and verify only the requested CFB week', function () {
    $definition = [
        'sport' => 'cfb', 'game' => Game::class, 'team' => Team::class,
        'metric' => TeamMetric::class, 'legacy_prediction' => Prediction::class,
        'generator' => GenerateCanonicalPrediction::class, 'registrar' => CfbCalculationReleaseRegistrar::class,
        'readiness' => CfbCanonicalCutoverReadinessService::class, 'team_names' => ['school' => 'University', 'mascot' => 'Hawks'],
        'metric_season_type' => false,
    ];
    $weekOne = canonicalFootballFixture($definition);
    $weekTwo = canonicalFootballFixture([
        ...$definition,
        'home_abbreviation' => 'H2',
        'away_abbreviation' => 'A2',
    ]);
    $weekTwo['event']->update(['week' => 2]);
    $weekTwo['game']->update(['week' => 2]);

    app(CfbCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());

    $this->artisan('cfb:generate-canonical-predictions', [
        '--season' => 2026,
        '--week' => 1,
    ])->assertSuccessful();

    expect(CanonicalPrediction::query()->count())->toBe(1)
        ->and(CanonicalPrediction::query()->sole()->sport_event_id)->toBe($weekOne['event']->getKey())
        ->and(app(CfbCanonicalCutoverReadinessService::class)->report(2026, 1)['ready_for_cutover'])->toBeTrue()
        ->and(app(CfbCanonicalCutoverReadinessService::class)->report(2026, 2)['ready_for_cutover'])->toBeFalse();
});

it('holds an apparent CFB edge without two priced books and calibrated cover evidence', function () {
    $definition = [
        'sport' => 'cfb', 'game' => Game::class, 'team' => Team::class,
        'metric' => TeamMetric::class, 'legacy_prediction' => Prediction::class,
        'generator' => GenerateCanonicalPrediction::class, 'registrar' => CfbCalculationReleaseRegistrar::class,
        'readiness' => CfbCanonicalCutoverReadinessService::class, 'team_names' => ['school' => 'University', 'mascot' => 'Hawks'],
        'metric_season_type' => false,
    ];
    $fixture = canonicalFootballFixture($definition);
    app(CfbCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    foreach (['home', 'away'] as $side) {
        FpiRating::factory()->create(['team_id' => $fixture[$side]->id, 'season' => 2026, 'week' => 0, 'fpi' => $side === 'home' ? 15 : 0]);
    }
    $this->mock(CfbPlayerEvidenceService::class)->shouldReceive('quarterback')->andReturn(['status' => 'last_observed']);
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']);
    $modelHomeLine = (float) $prediction->markets
        ->where('market_type', 'spread')
        ->where('selection', 'home')
        ->sole()
        ->projected_line;
    $marketHomeLine = $modelHomeLine - 14;
    $fixture['game']->update([
        'odds_updated_at' => now(),
        'odds_data' => [
            'bookmakers' => [[
                'key' => 'consensus',
                'markets' => [[
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'University', 'point' => $marketHomeLine],
                        ['name' => 'College', 'point' => -$marketHomeLine],
                    ],
                ]],
            ]],
        ],
    ]);
    $staleCapturedAt = now()->subDay();
    $oddsSnapshot = GameOddsSnapshot::query()->create([
        'sport' => 'cfb',
        'game_table' => 'cfb_games',
        'game_id' => $fixture['game']->getKey(),
        'source' => 'test',
        'captured_at' => $staleCapturedAt,
        'payload_hash' => hash('sha256', 'stale-cfb-spread-snapshot'),
        'odds_data' => $fixture['game']->fresh()->odds_data,
    ]);
    MarketQuote::query()->create([
        'game_odds_snapshot_id' => $oddsSnapshot->getKey(),
        'sport' => 'cfb',
        'game_table' => 'cfb_games',
        'game_id' => $fixture['game']->getKey(),
        'source' => 'test',
        'bookmaker_key' => 'consensus',
        'market_key' => 'spreads',
        'side' => 'home',
        'line' => $marketHomeLine,
        'bookmaker_home_line' => $marketHomeLine,
        'home_margin_equivalent' => -$marketHomeLine,
        'captured_at' => $staleCapturedAt,
        'is_pregame' => true,
        'quote_hash' => hash('sha256', 'stale-cfb-spread-quote'),
    ]);

    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    config()->set('prediction_lifecycle.canonical_reads.cfb', true);
    Sanctum::actingAs($user);

    $this->getJson('/api/v2/sports/cfb/predictions?season=2026&week=1')->assertOk()
        ->assertJsonPath('data.0.value_signal.has_playable_value', false)
        ->assertJsonPath('data.0.value_signal.spread_assessment.status', 'insufficient_evidence')
        ->assertJsonPath('data.0.value_signal.market_confirmation.supported', false);
    MarketQuote::query()->update(['captured_at' => now()]);

    $this->getJson('/api/v2/sports/cfb/predictions?season=2026&week=1')
        ->assertOk()
        ->assertJsonPath('data.0.value_signal.has_playable_value', false)
        ->assertJsonPath('data.0.value_signal.play_count', 0)
        ->assertJsonPath('data.0.value_signal.best.side', 'away')
        ->assertJsonPath('data.0.value_signal.best.edge', 14)
        ->assertJsonPath('data.0.value_signal.best.is_key_edge', false)
        ->assertJsonPath('data.0.value_signal.best.stats_supported', true)
        ->assertJsonPath('data.0.value_signal.best.grade', 'Watch')
        ->assertJsonPath('data.0.value_signal.market_confirmation.supported', false)
        ->assertJsonPath('data.0.value_signal.cover_probability_evidence.status', 'unavailable')
        ->assertJsonPath('data.0.market_summary.has_odds', true)
        ->assertJson(fn ($json) => $json->whereType('data.0.value_signal.best.label', 'string')->etc());

    MarketQuote::query()->delete();
    $fixture['game']->update([
        'odds_updated_at' => now(),
        'odds_data' => [
            'bookmakers' => [[
                'key' => 'consensus',
                'markets' => [[
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'University', 'point' => $modelHomeLine - 2],
                        ['name' => 'College', 'point' => -($modelHomeLine - 2)],
                    ],
                ]],
            ]],
        ],
    ]);

    $this->getJson('/api/v2/sports/cfb/predictions?season=2026&week=1')
        ->assertOk()
        ->assertJsonPath('data.0.market_summary.has_odds', true)
        ->assertJsonPath('data.0.value_signal.has_playable_value', false)
        ->assertJsonPath('data.0.value_signal.best', null);
});

it('keeps a large CFB spread disagreement on watch when team samples do not support it', function () {
    $definition = [
        'sport' => 'cfb', 'game' => Game::class, 'team' => Team::class,
        'metric' => TeamMetric::class, 'legacy_prediction' => Prediction::class,
        'generator' => GenerateCanonicalPrediction::class, 'registrar' => CfbCalculationReleaseRegistrar::class,
        'readiness' => CfbCanonicalCutoverReadinessService::class, 'team_names' => ['school' => 'University', 'mascot' => 'Hawks'],
        'metric_season_type' => false,
    ];
    $fixture = canonicalFootballFixture($definition);
    TeamMetric::query()->update(['wins' => 1, 'losses' => 0]);
    app(CfbCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']);
    $modelHomeLine = (float) $prediction->markets
        ->where('market_type', 'spread')
        ->where('selection', 'home')
        ->sole()
        ->projected_line;
    $marketHomeLine = $modelHomeLine - 14;
    $fixture['game']->update([
        'odds_updated_at' => now(),
        'odds_data' => [
            'bookmakers' => [[
                'key' => 'consensus',
                'markets' => [[
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'University', 'point' => $marketHomeLine],
                        ['name' => 'College', 'point' => -$marketHomeLine],
                    ],
                ]],
            ]],
        ],
    ]);

    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    config()->set('prediction_lifecycle.canonical_reads.cfb', true);
    Sanctum::actingAs($user);

    $this->getJson('/api/v2/sports/cfb/predictions?season=2026&week=1')
        ->assertOk()
        ->assertJsonPath('data.0.value_signal.has_playable_value', false)
        ->assertJsonPath('data.0.value_signal.best.side', 'away')
        ->assertJsonPath('data.0.value_signal.best.edge', 14)
        ->assertJsonPath('data.0.value_signal.best.is_key_edge', false)
        ->assertJsonPath('data.0.value_signal.best.stats_supported', false)
        ->assertJsonPath('data.0.value_signal.best.grade', 'Watch')
        ->assertJsonPath('data.0.value_signal.best.statistical_support.home_sample_games', 1)
        ->assertJsonPath('data.0.value_signal.best.statistical_support.away_sample_games', 1);
});

it('suppresses a CFB spread disagreement when the model has only default inputs', function () {
    $definition = [
        'sport' => 'cfb', 'game' => Game::class, 'team' => Team::class,
        'metric' => TeamMetric::class, 'legacy_prediction' => Prediction::class,
        'generator' => GenerateCanonicalPrediction::class, 'registrar' => CfbCalculationReleaseRegistrar::class,
        'readiness' => CfbCanonicalCutoverReadinessService::class, 'team_names' => ['school' => 'University', 'mascot' => 'Hawks'],
        'metric_season_type' => false,
    ];
    $fixture = canonicalFootballFixture($definition);
    $fixture['home']->update(['elo_rating' => 1500]);
    $fixture['away']->update(['elo_rating' => 1500]);
    TeamMetric::query()->update(['wins' => 0, 'losses' => 0]);
    app(CfbCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']);
    $modelHomeLine = (float) $prediction->markets
        ->where('market_type', 'spread')
        ->where('selection', 'home')
        ->sole()
        ->projected_line;
    $marketHomeLine = $modelHomeLine - 14;
    $fixture['game']->update([
        'odds_updated_at' => now(),
        'odds_data' => [
            'bookmakers' => [[
                'key' => 'consensus',
                'markets' => [[
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'University', 'point' => $marketHomeLine],
                        ['name' => 'College', 'point' => -$marketHomeLine],
                    ],
                ]],
            ]],
        ],
    ]);

    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    config()->set('prediction_lifecycle.canonical_reads.cfb', true);
    Sanctum::actingAs($user);

    $this->getJson('/api/v2/sports/cfb/predictions?season=2026&week=1')
        ->assertOk()
        ->assertJsonPath('data.0.value_signal.has_playable_value', false)
        ->assertJsonPath('data.0.value_signal.play_count', 0)
        ->assertJsonPath('data.0.value_signal.best', null);
});

it('runs canonical football commands and evaluation idempotently', function (array $definition) {
    $fixture = canonicalFootballFixture($definition);
    $sport = $definition['sport'];
    $this->artisan("{$sport}:register-calculation-release", ['--effective-at' => now()->subMinute()->toIso8601String()])->assertSuccessful();
    $this->artisan("{$sport}:generate-canonical-predictions", ['--game' => $fixture['game']->getKey()])->assertSuccessful();
    $this->artisan("{$sport}:generate-canonical-predictions", ['--game' => $fixture['game']->getKey()])->assertSuccessful();
    expect(EventInputSnapshot::query()->count())->toBe(1)->and(CalculationRun::query()->count())->toBe(1)
        ->and(CanonicalPrediction::query()->count())->toBe(1)->and(PredictionMarket::query()->count())->toBe(4);
    $this->travel(2)->days();
    $fixture['game']->update(['status' => 'STATUS_FINAL', 'home_score' => 31, 'away_score' => 20, 'game_clock' => '0:00']);
    $this->artisan("{$sport}:evaluate-canonical-predictions", ['--game' => $fixture['game']->getKey()])->assertSuccessful();
    $this->artisan("{$sport}:evaluate-canonical-predictions", ['--game' => $fixture['game']->getKey()])->assertSuccessful();
    expect(SportEventResult::query()->count())->toBe(1)->and(PredictionEvaluation::query()->count())->toBe(1)
        ->and(data_get(PredictionEvaluation::query()->sole()->actuals, 'home_margin'))->toBe(11);
})->with('canonical football sports');

it('serves strict football reads only after readiness passes', function (array $definition) {
    if ($definition['sport'] === 'nfl') {
        config()->set('prediction_lifecycle.canonical_pipeline.nfl', true);
        config()->set('nfl.predictions.true_epa.enabled', true);
        config()->set('nfl.predictions.true_epa.backfill_before_generation', true);
        config()->set('nfl_research.enabled', true);
    }

    $fixture = canonicalFootballFixture($definition);
    if ($definition['sport'] === 'nfl') {
        prepareNflCanonicalReadinessFixture($fixture);
    }
    $historical = canonicalFootballFixture([
        ...$definition,
        'home_abbreviation' => 'HIS',
        'away_abbreviation' => 'OLD',
    ]);
    $historicalStartsAt = now()->subHours(2)->startOfHour();
    $historical['event']->update([
        'starts_at' => $historicalStartsAt,
        'status' => 'STATUS_FINAL',
    ]);
    $historical['game']->update([
        'game_date' => $historicalStartsAt,
        'status' => 'STATUS_FINAL',
        'home_score' => 24,
        'away_score' => 17,
    ]);
    $readiness = app($definition['readiness']);
    expect($readiness->report(2026)['ready_for_cutover'])->toBeFalse();
    app($definition['registrar'])->register(effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app($definition['generator'])->execute($fixture['game']);
    $report = $readiness->report(2026);
    expect($report['ready_for_cutover'])->toBeTrue()
        ->and($report['eligible_event_count'])->toBe(1)
        ->and($report['pre_cutover_event_count'])->toBe(1);
    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    config()->set("prediction_lifecycle.canonical_reads.{$definition['sport']}", true);
    Sanctum::actingAs($user);
    $this->getJson("/api/v2/sports/{$definition['sport']}/predictions?season=2026")->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.prediction_source', 'canonical')->assertJsonPath('data.0.id', $prediction->public_id)
        ->assertJsonPath('data.0.game_id', $fixture['game']->getKey())->assertJsonPath('data.0.value_signal', null);
})->with('canonical football sports');
