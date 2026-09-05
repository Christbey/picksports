<?php

use App\Actions\NBA\GenerateCanonicalPrediction;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction as LegacyNbaPrediction;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;
use App\Models\PredictionEvaluation;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\SportEventResult;
use App\Models\User;
use App\Services\NBA\Predictions\NbaCalculationReleaseRegistrar;
use App\Services\NBA\Predictions\NbaCanonicalCutoverReadinessService;
use Laravel\Sanctum\Sanctum;

/** @return array{event:SportEvent,game:Game,home:Team,away:Team} */
function canonicalNbaFixture(): array
{
    $startsAt = now()->addDay()->startOfHour();
    $event = SportEvent::factory()->create([
        'sport' => 'nba',
        'season' => 2026,
        'season_type' => '2',
        'starts_at' => $startsAt,
        'status' => 'STATUS_SCHEDULED',
    ]);
    $home = Team::factory()->create([
        'location' => 'Denver',
        'name' => 'Nuggets',
        'abbreviation' => 'DEN',
        'elo_rating' => 1620,
    ]);
    $away = Team::factory()->create([
        'location' => 'Portland',
        'name' => 'Trail Blazers',
        'abbreviation' => 'POR',
        'elo_rating' => 1440,
    ]);

    foreach ([
        [$home, 118.0, 110.0, 8.0, 101.0, 2.5],
        [$away, 108.0, 117.0, -9.0, 99.0, -2.0],
    ] as [$team, $offense, $defense, $net, $tempo, $recent]) {
        TeamMetric::query()->create([
            'team_id' => $team->getKey(),
            'season' => 2026,
            'season_type' => '2',
            'wins' => 28,
            'losses' => 12,
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

    $game = Game::factory()->create([
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

it('generates a canonical NBA prediction from a frozen release without legacy writes', function () {
    $fixture = canonicalNbaFixture();
    $release = app(NbaCalculationReleaseRegistrar::class)->register(
        effectiveAt: now()->subMinute()->toImmutable(),
    );
    config()->set('nba.elo.home_court_advantage', -1000);

    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']);
    $homeMoneyline = $prediction->markets
        ->where('market_type', 'moneyline')
        ->where('selection', 'home')
        ->sole();
    $homeSpread = $prediction->markets
        ->where('market_type', 'spread')
        ->where('selection', 'home')
        ->sole();
    $total = $prediction->markets->where('market_type', 'total')->sole();

    expect($prediction->sport)->toBe('nba')
        ->and($prediction->publication_state)->toBe('published')
        ->and($prediction->revision)->toBe(1)
        ->and($prediction->calculationRun->release->is($release))->toBeTrue()
        ->and($prediction->calculationRun->inputSnapshot->pregame_safety_status)->toBe('verified')
        ->and(data_get($prediction->calculationRun->inputSnapshot->inputs, 'home.elo'))->toBe(1620)
        ->and(data_get($prediction->calculationRun->inputSnapshot->inputs, 'away.metrics.net_rating'))->toBe(-9)
        ->and((float) $homeMoneyline->probability)->toBeGreaterThan(0.5)
        ->and((float) $homeSpread->projected_line)->toBeLessThan(0)
        ->and((float) $total->projected_line)->toBeGreaterThan(180)
        ->and(data_get($prediction->output_metadata, 'market_conventions.spread'))->toBe('sportsbook_home_line')
        ->and(LegacyNbaPrediction::query()->count())->toBe(0);
});

it('registers and generates NBA canonical predictions idempotently through commands', function () {
    $fixture = canonicalNbaFixture();

    $this->artisan('nba:register-calculation-release', [
        '--effective-at' => now()->subMinute()->toIso8601String(),
    ])->expectsOutputToContain('approved')->assertSuccessful();

    $this->artisan('nba:generate-canonical-predictions', [
        '--game' => $fixture['game']->getKey(),
    ])->expectsOutputToContain('1 succeeded, 0 failed')->assertSuccessful();

    $this->artisan('nba:generate-canonical-predictions', [
        '--game' => $fixture['game']->getKey(),
    ])->expectsOutputToContain('1 succeeded, 0 failed')->assertSuccessful();

    expect(CalculationRelease::query()->count())->toBe(1)
        ->and(EventInputSnapshot::query()->count())->toBe(1)
        ->and(CalculationRun::query()->count())->toBe(1)
        ->and(CanonicalPrediction::query()->count())->toBe(1)
        ->and(PredictionMarket::query()->count())->toBe(4)
        ->and(LegacyNbaPrediction::query()->count())->toBe(0);
});

it('evaluates NBA results idempotently and appends corrected result history', function () {
    $fixture = canonicalNbaFixture();
    app(NbaCalculationReleaseRegistrar::class)->register(
        effectiveAt: now()->subMinute()->toImmutable(),
    );
    app(GenerateCanonicalPrediction::class)->execute($fixture['game']);

    $this->travel(2)->days();
    $fixture['game']->update([
        'status' => 'STATUS_FINAL',
        'home_score' => 119,
        'away_score' => 111,
        'game_clock' => '0:00',
    ]);

    $this->artisan('nba:evaluate-canonical-predictions', [
        '--game' => $fixture['game']->getKey(),
    ])->expectsOutputToContain('1 evaluated, 0 skipped, 0 failed')->assertSuccessful();
    $this->artisan('nba:evaluate-canonical-predictions', [
        '--game' => $fixture['game']->getKey(),
    ])->assertSuccessful();

    expect(SportEventResult::query()->count())->toBe(1)
        ->and(PredictionEvaluation::query()->count())->toBe(1)
        ->and(data_get(PredictionEvaluation::query()->sole()->actuals, 'home_margin'))->toBe(8);

    $fixture['game']->update(['home_score' => 120]);
    $this->artisan('nba:evaluate-canonical-predictions', [
        '--game' => $fixture['game']->getKey(),
    ])->assertSuccessful();

    $results = SportEventResult::query()->orderBy('revision')->get();
    $evaluations = PredictionEvaluation::query()->orderBy('evaluation_revision')->get();

    expect($results)->toHaveCount(2)
        ->and($results[1]->supersedes->is($results[0]))->toBeTrue()
        ->and($evaluations)->toHaveCount(2)
        ->and($evaluations[1]->supersedes->is($evaluations[0]))->toBeTrue()
        ->and(data_get($evaluations[1]->actuals, 'home_score'))->toBe(120);
});

it('serves provenance-safe NBA canonical outputs and reports cutover readiness', function () {
    $fixture = canonicalNbaFixture();
    $readiness = app(NbaCanonicalCutoverReadinessService::class);

    expect($readiness->report(2026)['ready_for_cutover'])->toBeFalse();

    app(NbaCalculationReleaseRegistrar::class)->register(
        effectiveAt: now()->subMinute()->toImmutable(),
    );
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']);

    expect($readiness->report(2026)['ready_for_cutover'])->toBeTrue();

    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    config()->set('prediction_lifecycle.canonical_reads.nba', true);
    Sanctum::actingAs($user);

    $this->getJson('/api/v2/sports/nba/predictions?season=2026')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.prediction_source', 'canonical')
        ->assertJsonPath('data.0.id', $prediction->public_id)
        ->assertJsonPath('data.0.game_id', $fixture['game']->getKey())
        ->assertJsonPath('data.0.sport_event_id', $fixture['event']->public_id)
        ->assertJsonPath('data.0.audit_context.calculation_release_version', $prediction->model_version)
        ->assertJsonPath('data.0.value_signal', null);

    $this->getJson("/api/v2/sports/nba/predictions/{$prediction->public_id}")
        ->assertOk()
        ->assertJsonPath('data.id', $prediction->public_id);

    $this->getJson("/api/v2/sports/nba/games/{$fixture['game']->getKey()}/prediction")
        ->assertOk()
        ->assertJsonPath('data.id', $prediction->public_id);
});
