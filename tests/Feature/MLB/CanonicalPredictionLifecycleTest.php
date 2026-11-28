<?php

use App\Actions\MLB\GenerateCanonicalPrediction;
use App\Exceptions\Predictions\PredictionLifecycleException;
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
use App\Models\PredictionPublicationEffect;
use App\Models\SportEvent;
use App\Models\SportEventResult;
use App\Models\User;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Api\V2\SportContextResolver;
use App\Services\MLB\Predictions\MlbCalculationReleaseRegistrar;
use App\Services\MLB\Predictions\MlbCanonicalCutoverReadinessService;
use App\Services\Predictions\CanonicalShadowInputs;
use App\Services\Predictions\PredictionPublisher;
use App\Services\Predictions\PublicationEffectDelivery;
use App\Support\MLB\MlbGameStart;
use App\Support\MLB\MlbWeatherTime;
use App\Support\Sports\CanonicalEventStart;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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

    $game = Game::factory()->withSyntheticWallStart((string) config('app.timezone'))->create([
        'sport_event_id' => $event->getKey(), 'home_team_id' => $home->getKey(), 'away_team_id' => $away->getKey(),
        'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_SCHEDULED', 'game_date' => $startsAt, 'game_time' => $startsAt->format('H:i:s'),
        'home_score' => null, 'away_score' => null, 'venue_name' => 'Wrigley Field',
        'probable_home_pitcher_espn_id' => $homePitcher->espn_id, 'probable_away_pitcher_espn_id' => $awayPitcher->espn_id,
    ]);
    $weather = ['provider' => 'test', 'temperature_f' => 82, 'wind_speed_mph' => 10, 'is_indoor' => false, 'roof_status' => 'open'];
    GameWeather::query()->create($weather + [
        'game_id' => $game->getKey(), 'observed_at' => now(),
        'raw_payload' => ['_mlb_weather_time' => MlbWeatherTime::capture($game, $startsAt, $weather)],
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

it('serves strict MLB canonical reads without mistaking original rules for full model readiness', function () {
    $fixture = canonicalMlbFixture();
    $readiness = app(MlbCanonicalCutoverReadinessService::class);
    expect($readiness->report(2026)['ready_for_cutover'])->toBeFalse();
    app(MlbCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']);
    expect($readiness->report(2026)['ready_for_cutover'])->toBeFalse();
    expect($readiness->report(2026)['readiness_blockers'])->toContain('established_model_release_required');
    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    config()->set('prediction_lifecycle.canonical_reads.mlb', true);
    Sanctum::actingAs($user);
    $this->getJson('/api/v2/sports/mlb/predictions?season=2026')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.prediction_source', 'canonical')->assertJsonPath('data.0.id', $prediction->public_id)
        ->assertJsonPath('data.0.game_id', $fixture['game']->getKey())->assertJsonPath('data.0.value_signal', null);
});

it('declines stale MLB canonical captures after a schedule correction without rewriting published history', function () {
    $fixture = canonicalMlbFixture();
    app(MlbCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']);
    $event = $prediction->sportEvent;
    $input = $prediction->calculationRun->inputSnapshot;
    $priorInput = $input->getAttributes();
    $priorPrediction = $prediction->getAttributes();
    $query = app(CanonicalSportPredictionQuery::class);
    $shadow = app(CanonicalShadowInputs::class);
    expect(CanonicalEventStart::snapshot($event, $input))->toBeTrue()
        ->and($query->queryForSport('mlb')->count())->toBe(1)
        ->and($shadow->fromPrediction($prediction))->not->toBeNull();

    $game = $fixture['game']->fresh();
    $instant = MlbGameStart::for($game)->addHour();
    $wall = $instant->copy()->setTimezone(config('app.timezone'));
    $game->update(MlbGameStart::prepare($game, [
        'game_date' => $wall->toDateString(), 'game_time' => $wall->toTimeString(),
    ] + MlbGameStart::observed($instant->toIso8601String(), [])));
    expect(CanonicalEventStart::snapshot($event, $input))->toBeFalse()
        ->and($query->queryForSport('mlb')->count())->toBe(0)
        ->and($shadow->fromPrediction($prediction))->toBeNull()
        ->and($input->fresh()->getAttributes())->toBe($priorInput)
        ->and($prediction->fresh()->getAttributes())->toBe($priorPrediction);
});

it('lists canonical MLB dates from the verified instant rather than treating venue walls as UTC', function () {
    $this->travelTo(Carbon::parse('2026-07-01T06:00:00Z'));
    $fixture = canonicalMlbFixture();
    app(MlbCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    app(GenerateCanonicalPrediction::class)->execute($fixture['game']);
    config(['prediction_lifecycle.canonical_reads.mlb' => true]);
    $context = app(SportContextResolver::class)->resolve('mlb');
    expect(app(CanonicalSportPredictionQuery::class)->availableDates($context)->all())->toBe(['2026-07-02']);
});

it('refuses publication of a frozen MLB draft after its observed start context changes', function () {
    $fixture = canonicalMlbFixture();
    app(MlbCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    $draft = app(GenerateCanonicalPrediction::class)->execute($fixture['game'], publish: false);
    $input = $draft->calculationRun->inputSnapshot;
    $priorInput = $input->getAttributes();
    $game = $fixture['game']->fresh();
    $instant = MlbGameStart::for($game)->addHour();
    $wall = $instant->copy()->setTimezone(config('app.timezone'));
    $game->update(MlbGameStart::prepare($game, ['game_date' => $wall->toDateString(), 'game_time' => $wall->toTimeString()]
        + MlbGameStart::observed($instant->toIso8601String(), [])));
    expect(fn () => app(PredictionPublisher::class)->publish($draft))->toThrow(PredictionLifecycleException::class)
        ->and($draft->fresh()->publication_state)->toBe('draft')
        ->and($input->fresh()->getAttributes())->toBe($priorInput);
});

it('cancels queued MLB narrative admission when its frozen schedule observation is no longer current', function () {
    Http::preventStrayRequests();
    $fixture = canonicalMlbFixture();
    app(MlbCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']);
    $effect = PredictionPublicationEffect::updateOrCreate(
        ['canonical_prediction_id' => $prediction->id, 'effect' => 'narrative'], ['payload' => ['sport' => 'mlb'],
            'state' => 'queued', 'owner_token' => 'synthetic-current-lease', 'lease_expires_at' => now()->addMinute(),
        ]);
    $game = $fixture['game']->fresh();
    $instant = MlbGameStart::for($game)->addHour();
    $wall = $instant->copy()->setTimezone(config('app.timezone'));
    $game->update(MlbGameStart::prepare($game, ['game_date' => $wall->toDateString(), 'game_time' => $wall->toTimeString()]
        + MlbGameStart::observed($instant->toIso8601String(), [])));
    app(PublicationEffectDelivery::class)->process($effect->id, 'synthetic-current-lease');
    expect($effect->fresh()->state)->toBe('cancelled')->and($effect->fresh()->reason_code)->toBe('mlb_schedule_provenance_unavailable');
    Http::assertNothingSent();
});
