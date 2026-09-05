<?php

use App\Actions\CBB\GenerateCanonicalPrediction;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use App\Models\CBB\Team;
use App\Models\CBB\TeamMetric;
use App\Models\EventInputSnapshot;
use App\Models\PredictionEvaluation;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\SportEventResult;
use App\Models\User;
use App\Services\CBB\Predictions\CbbCalculationReleaseRegistrar;
use App\Services\CBB\Predictions\CbbCanonicalCutoverReadinessService;
use App\Services\WCBB\Predictions\WcbbCalculationReleaseRegistrar;
use App\Services\WCBB\Predictions\WcbbCanonicalCutoverReadinessService;
use Laravel\Sanctum\Sanctum;

dataset('canonical college basketball sports', [
    'CBB' => [[
        'sport' => 'cbb',
        'game' => Game::class,
        'team' => Team::class,
        'metric' => TeamMetric::class,
        'legacy_prediction' => Prediction::class,
        'generator' => GenerateCanonicalPrediction::class,
        'registrar' => CbbCalculationReleaseRegistrar::class,
        'readiness' => CbbCanonicalCutoverReadinessService::class,
    ]],
    'WCBB' => [[
        'sport' => 'wcbb',
        'game' => App\Models\WCBB\Game::class,
        'team' => App\Models\WCBB\Team::class,
        'metric' => App\Models\WCBB\TeamMetric::class,
        'legacy_prediction' => App\Models\WCBB\Prediction::class,
        'generator' => App\Actions\WCBB\GenerateCanonicalPrediction::class,
        'registrar' => WcbbCalculationReleaseRegistrar::class,
        'readiness' => WcbbCanonicalCutoverReadinessService::class,
    ]],
]);

/** @param array<string, class-string|string> $definition @return array<string, mixed> */
function canonicalCollegeBasketballFixture(array $definition): array
{
    $sport = $definition['sport'];
    $startsAt = now()->addDay()->startOfHour();
    $event = SportEvent::factory()->create([
        'sport' => $sport,
        'season' => 2026,
        'season_type' => '2',
        'starts_at' => $startsAt,
        'status' => 'STATUS_SCHEDULED',
    ]);
    $teamClass = $definition['team'];
    $home = $teamClass::factory()->create([
        'school' => 'Home University',
        'mascot' => 'Hawks',
        'abbreviation' => 'HOM',
        'elo_rating' => 1620,
    ]);
    $away = $teamClass::factory()->create([
        'school' => 'Away University',
        'mascot' => 'Bears',
        'abbreviation' => 'AWY',
        'elo_rating' => 1440,
    ]);
    $metricClass = $definition['metric'];

    foreach ([
        [$home, 115.0, 98.0, 17.0, 72.0, 2.5],
        [$away, 99.0, 112.0, -13.0, 68.0, -2.0],
    ] as [$team, $offense, $defense, $net, $tempo, $recent]) {
        $metricClass::query()->create([
            'team_id' => $team->getKey(),
            'season' => 2026,
            'wins' => 22,
            'losses' => 8,
            'offensive_efficiency' => $offense,
            'defensive_efficiency' => $defense,
            'net_rating' => $net,
            'tempo' => $tempo,
            'strength_of_schedule' => 0,
            'recent_form_rating' => $recent,
            'injury_adjusted_team_rating' => $team->elo_rating,
            'injury_total_adjustment' => 0,
            'rest_travel_fatigue' => 0,
            'calculation_date' => now()->toDateString(),
        ]);
    }

    $gameClass = $definition['game'];
    $game = $gameClass::factory()->create([
        'sport_event_id' => $event->getKey(),
        'home_team_id' => $home->getKey(),
        'away_team_id' => $away->getKey(),
        'season' => 2026,
        'season_type' => 2,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => $startsAt,
        'home_score' => null,
        'away_score' => null,
    ]);

    return compact('event', 'game', 'home', 'away');
}

it('generates immutable college basketball predictions from frozen releases', function (array $definition) {
    $fixture = canonicalCollegeBasketballFixture($definition);
    $release = app($definition['registrar'])->register(effectiveAt: now()->subMinute()->toImmutable());
    config()->set("{$definition['sport']}.elo.home_court_advantage", -1000);

    $prediction = app($definition['generator'])->execute($fixture['game']);
    $moneyline = $prediction->markets->where('market_type', 'moneyline')->where('selection', 'home')->sole();
    $spread = $prediction->markets->where('market_type', 'spread')->where('selection', 'home')->sole();
    $total = $prediction->markets->where('market_type', 'total')->sole();
    $legacyPredictionClass = $definition['legacy_prediction'];

    expect($prediction->sport)->toBe($definition['sport'])
        ->and($prediction->publication_state)->toBe('published')
        ->and($prediction->calculationRun->release->is($release))->toBeTrue()
        ->and($prediction->calculationRun->inputSnapshot->pregame_safety_status)->toBe('verified')
        ->and(data_get($prediction->calculationRun->inputSnapshot->inputs, 'away.metrics.net_rating'))->toBe(-13)
        ->and((float) $moneyline->probability)->toBeGreaterThan(0.5)
        ->and((float) $spread->projected_line)->toBeLessThan(0)
        ->and((float) $total->projected_line)->toBeGreaterThan(100)
        ->and($legacyPredictionClass::query()->count())->toBe(0);
})->with('canonical college basketball sports');

it('runs college basketball generation and evaluation commands idempotently', function (array $definition) {
    $fixture = canonicalCollegeBasketballFixture($definition);
    $sport = $definition['sport'];

    $this->artisan("{$sport}:register-calculation-release", [
        '--effective-at' => now()->subMinute()->toIso8601String(),
    ])->assertSuccessful();
    $this->artisan("{$sport}:generate-canonical-predictions", [
        '--game' => $fixture['game']->getKey(),
    ])->assertSuccessful();
    $this->artisan("{$sport}:generate-canonical-predictions", [
        '--game' => $fixture['game']->getKey(),
    ])->assertSuccessful();

    expect(EventInputSnapshot::query()->count())->toBe(1)
        ->and(CalculationRun::query()->count())->toBe(1)
        ->and(CanonicalPrediction::query()->count())->toBe(1)
        ->and(PredictionMarket::query()->count())->toBe(4);

    $this->travel(2)->days();
    $fixture['game']->update([
        'status' => 'STATUS_FINAL',
        'home_score' => 78,
        'away_score' => 70,
        'game_clock' => '0:00',
    ]);
    $this->artisan("{$sport}:evaluate-canonical-predictions", [
        '--game' => $fixture['game']->getKey(),
    ])->assertSuccessful();
    $this->artisan("{$sport}:evaluate-canonical-predictions", [
        '--game' => $fixture['game']->getKey(),
    ])->assertSuccessful();

    expect(SportEventResult::query()->count())->toBe(1)
        ->and(PredictionEvaluation::query()->count())->toBe(1)
        ->and(data_get(PredictionEvaluation::query()->sole()->actuals, 'home_margin'))->toBe(8);
})->with('canonical college basketball sports');

it('serves strict canonical college basketball reads after readiness passes', function (array $definition) {
    $fixture = canonicalCollegeBasketballFixture($definition);
    $readiness = app($definition['readiness']);

    expect($readiness->report(2026)['ready_for_cutover'])->toBeFalse();
    app($definition['registrar'])->register(effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app($definition['generator'])->execute($fixture['game']);
    expect($readiness->report(2026)['ready_for_cutover'])->toBeTrue();

    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    config()->set("prediction_lifecycle.canonical_reads.{$definition['sport']}", true);
    Sanctum::actingAs($user);

    $this->getJson("/api/v2/sports/{$definition['sport']}/predictions?season=2026")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.prediction_source', 'canonical')
        ->assertJsonPath('data.0.id', $prediction->public_id)
        ->assertJsonPath('data.0.game_id', $fixture['game']->getKey())
        ->assertJsonPath('data.0.sport_event_id', $fixture['event']->public_id)
        ->assertJsonPath('data.0.value_signal', null);
})->with('canonical college basketball sports');
