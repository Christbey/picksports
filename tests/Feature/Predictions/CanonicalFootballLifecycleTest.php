<?php

use App\Actions\CFB\GenerateCanonicalPrediction;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Models\EventInputSnapshot;
use App\Models\PredictionEvaluation;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\SportEventResult;
use App\Models\User;
use App\Services\CFB\Predictions\CfbCalculationReleaseRegistrar;
use App\Services\CFB\Predictions\CfbCanonicalCutoverReadinessService;
use App\Services\NFL\Predictions\NflCalculationReleaseRegistrar;
use App\Services\NFL\Predictions\NflCanonicalCutoverReadinessService;
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

    return compact('event', 'game', 'home', 'away');
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

it('highlights a statistically supported CFB away cover edge against an inflated favorite line', function () {
    $definition = [
        'sport' => 'cfb', 'game' => Game::class, 'team' => Team::class,
        'metric' => TeamMetric::class, 'legacy_prediction' => Prediction::class,
        'generator' => GenerateCanonicalPrediction::class, 'registrar' => CfbCalculationReleaseRegistrar::class,
        'readiness' => CfbCanonicalCutoverReadinessService::class, 'team_names' => ['school' => 'University', 'mascot' => 'Hawks'],
        'metric_season_type' => false,
    ];
    $fixture = canonicalFootballFixture($definition);
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
        ->assertJsonPath('data.0.value_signal.has_playable_value', true)
        ->assertJsonPath('data.0.value_signal.play_count', 1)
        ->assertJsonPath('data.0.value_signal.best.side', 'away')
        ->assertJsonPath('data.0.value_signal.best.edge', 14)
        ->assertJsonPath('data.0.value_signal.best.is_key_edge', true)
        ->assertJsonPath('data.0.value_signal.best.stats_supported', true)
        ->assertJsonPath('data.0.value_signal.best.grade', 'Key')
        ->assertJsonPath('data.0.value_signal.best.statistical_support.home_sample_games', 12)
        ->assertJsonPath('data.0.value_signal.best.statistical_support.away_sample_games', 12)
        ->assertJsonPath('data.0.market_summary.has_odds', true)
        ->assertJson(fn ($json) => $json->whereType('data.0.value_signal.best.label', 'string')->etc());

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
    $fixture = canonicalFootballFixture($definition);
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
