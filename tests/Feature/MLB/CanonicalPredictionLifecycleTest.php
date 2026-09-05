<?php

use App\Actions\MLB\GenerateCanonicalPrediction;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\MLB\Game;
use App\Models\MLB\GameWeather;
use App\Models\MLB\Player;
use App\Models\MLB\Prediction as LegacyMlbPrediction;
use App\Models\MLB\Team;
use App\Models\MLB\TeamMetric;
use App\Models\PredictionEvaluation;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\SportEventResult;
use App\Models\User;
use App\Services\MLB\Predictions\MlbCalculationReleaseRegistrar;
use App\Services\MLB\Predictions\MlbCanonicalCutoverReadinessService;
use Laravel\Sanctum\Sanctum;

/** @return array<string,mixed> */
function canonicalMlbFixture(): array
{
    $startsAt = now()->addDay()->startOfHour();
    $event = SportEvent::factory()->create([
        'sport' => 'mlb', 'season' => 2026, 'season_type' => '2', 'starts_at' => $startsAt,
        'status' => 'STATUS_SCHEDULED', 'neutral_site' => false,
    ]);
    $home = Team::factory()->create(['location' => 'Chicago', 'name' => 'Cubs', 'abbreviation' => 'CHC', 'elo_rating' => 1580]);
    $away = Team::factory()->create(['location' => 'Pittsburgh', 'name' => 'Pirates', 'abbreviation' => 'PIT', 'elo_rating' => 1440]);
    $homePitcher = Player::factory()->create(['team_id' => $home->getKey(), 'espn_id' => 'home-starter', 'full_name' => 'Home Starter', 'elo_rating' => 1620]);
    $awayPitcher = Player::factory()->create(['team_id' => $away->getKey(), 'espn_id' => 'away-starter', 'full_name' => 'Away Starter', 'elo_rating' => 1400]);

    foreach ([[$home, 5.2, 3.8, 1.4], [$away, 3.7, 5.1, -1.4]] as [$team, $runs, $allowed, $differential]) {
        TeamMetric::query()->create([
            'team_id' => $team->getKey(), 'season' => 2026, 'season_type' => '2', 'wins' => 45, 'losses' => 30,
            'offensive_rating' => 110, 'pitching_rating' => 105, 'defensive_rating' => 100,
            'runs_per_game' => $runs, 'runs_allowed_per_game' => $allowed, 'run_differential_per_game' => $differential,
            'strength_of_schedule' => 0, 'recent_form_rating' => $differential,
            'injury_adjusted_team_rating' => $team->elo_rating, 'injury_total_adjustment' => 0,
            'rest_travel_fatigue' => 0, 'calculation_date' => now()->toDateString(),
        ]);
    }

    $game = Game::factory()->create([
        'sport_event_id' => $event->getKey(), 'home_team_id' => $home->getKey(), 'away_team_id' => $away->getKey(),
        'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_SCHEDULED', 'game_date' => $startsAt,
        'home_score' => null, 'away_score' => null, 'venue_name' => 'Wrigley Field',
        'probable_home_pitcher_espn_id' => $homePitcher->espn_id, 'probable_away_pitcher_espn_id' => $awayPitcher->espn_id,
    ]);
    GameWeather::query()->create([
        'game_id' => $game->getKey(), 'provider' => 'test', 'observed_at' => now()->subMinute(),
        'temperature_f' => 82, 'wind_speed_mph' => 10, 'is_indoor' => false, 'roof_status' => 'open',
    ]);

    return compact('event', 'game', 'home', 'away', 'homePitcher', 'awayPitcher');
}

it('generates MLB predictions from frozen pitcher weather and team evidence', function () {
    $fixture = canonicalMlbFixture();
    $release = app(MlbCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    config()->set('mlb.prediction.canonical.pitcher_elo_weight', 0);
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']);
    $snapshot = $prediction->calculationRun->inputSnapshot;
    $moneyline = $prediction->markets->where('market_type', 'moneyline')->where('selection', 'home')->sole();
    $spread = $prediction->markets->where('market_type', 'spread')->where('selection', 'home')->sole();

    expect($prediction->calculationRun->release->is($release))->toBeTrue()
        ->and($snapshot->pregame_safety_status)->toBe('verified')
        ->and(data_get($snapshot->inputs, 'pitching.home.espn_id'))->toBe('home-starter')
        ->and(data_get($snapshot->inputs, 'pitching.home.elo'))->toBe(1620)
        ->and(data_get($snapshot->inputs, 'weather.temperature_f'))->toBe(82)
        ->and((float) $moneyline->probability)->toBeGreaterThan(0.5)
        ->and((float) $spread->projected_line)->toBeLessThan(0)
        ->and(LegacyMlbPrediction::query()->count())->toBe(0);
});

it('runs MLB canonical commands and evaluation idempotently', function () {
    $fixture = canonicalMlbFixture();
    $this->artisan('mlb:register-calculation-release', ['--effective-at' => now()->subMinute()->toIso8601String()])->assertSuccessful();
    $this->artisan('mlb:generate-canonical-predictions', ['--game' => $fixture['game']->getKey()])->assertSuccessful();
    $this->artisan('mlb:generate-canonical-predictions', ['--game' => $fixture['game']->getKey()])->assertSuccessful();
    expect(EventInputSnapshot::query()->count())->toBe(1)->and(CalculationRun::query()->count())->toBe(1)
        ->and(CanonicalPrediction::query()->count())->toBe(1)->and(PredictionMarket::query()->count())->toBe(4);
    $this->travel(2)->days();
    $fixture['game']->update(['status' => 'STATUS_FINAL', 'home_score' => 6, 'away_score' => 3]);
    $this->artisan('mlb:evaluate-canonical-predictions', ['--game' => $fixture['game']->getKey()])->assertSuccessful();
    $this->artisan('mlb:evaluate-canonical-predictions', ['--game' => $fixture['game']->getKey()])->assertSuccessful();
    expect(SportEventResult::query()->count())->toBe(1)->and(PredictionEvaluation::query()->count())->toBe(1)
        ->and(data_get(PredictionEvaluation::query()->sole()->actuals, 'home_margin'))->toBe(3);
});

it('serves strict MLB canonical reads after readiness passes', function () {
    $fixture = canonicalMlbFixture();
    $readiness = app(MlbCanonicalCutoverReadinessService::class);
    expect($readiness->report(2026)['ready_for_cutover'])->toBeFalse();
    app(MlbCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']);
    expect($readiness->report(2026)['ready_for_cutover'])->toBeTrue();
    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    config()->set('prediction_lifecycle.canonical_reads.mlb', true);
    Sanctum::actingAs($user);
    $this->getJson('/api/v2/sports/mlb/predictions?season=2026')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.prediction_source', 'canonical')->assertJsonPath('data.0.id', $prediction->public_id)
        ->assertJsonPath('data.0.game_id', $fixture['game']->getKey())->assertJsonPath('data.0.value_signal', null);
});
