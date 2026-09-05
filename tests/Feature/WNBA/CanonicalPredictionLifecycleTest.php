<?php

use App\Actions\WNBA\GenerateCanonicalPrediction;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\PredictionEvaluation;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\SportEventResult;
use App\Models\User;
use App\Models\WNBA\Game;
use App\Models\WNBA\Prediction as LegacyWnbaPrediction;
use App\Models\WNBA\Team;
use App\Models\WNBA\TeamMetric;
use App\Services\Predictions\CanonicalPredictionReadRepository;
use App\Services\WNBA\Predictions\WnbaCalculationReleaseRegistrar;
use App\Services\WNBA\Predictions\WnbaCanonicalCutoverReadinessService;
use Laravel\Sanctum\Sanctum;

/** @return array{event:SportEvent,game:Game,home:Team,away:Team} */
function canonicalWnbaFixture(): array
{
    $startsAt = now()->addDay()->startOfHour();
    $event = SportEvent::factory()->create([
        'sport' => 'wnba',
        'season' => 2026,
        'season_type' => '2',
        'starts_at' => $startsAt,
        'status' => 'STATUS_SCHEDULED',
    ]);
    $home = Team::factory()->create([
        'location' => 'Las Vegas',
        'name' => 'Aces',
        'abbreviation' => 'LVC',
        'elo_rating' => 1600,
    ]);
    $away = Team::factory()->create([
        'location' => 'Chicago',
        'name' => 'Sky',
        'abbreviation' => 'CHC',
        'elo_rating' => 1450,
    ]);

    foreach ([
        [$home, 106.0, 96.0, 10.0, 89.0, 3.0],
        [$away, 98.0, 104.0, -6.0, 87.0, -1.0],
    ] as [$team, $offense, $defense, $net, $tempo, $recent]) {
        TeamMetric::query()->create([
            'team_id' => $team->getKey(),
            'season' => 2026,
            'season_type' => '2',
            'wins' => 12,
            'losses' => 4,
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
        'season_type' => '2',
        'status' => 'STATUS_SCHEDULED',
        'game_date' => $startsAt,
        'home_score' => null,
        'away_score' => null,
    ]);

    return compact('event', 'game', 'home', 'away');
}

it('generates a canonical-first WNBA prediction without touching the legacy prediction table', function () {
    $fixture = canonicalWnbaFixture();
    $release = app(WnbaCalculationReleaseRegistrar::class)->register(
        effectiveAt: now()->subMinute()->toImmutable(),
    );

    // Runtime configuration changes cannot alter an already frozen release.
    config()->set('wnba.elo.home_court_advantage', -1000);
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
    $summary = app(CanonicalPredictionReadRepository::class)->summaryForEvent($fixture['event']);

    expect($prediction->sport)->toBe('wnba')
        ->and($prediction->publication_state)->toBe('published')
        ->and($prediction->revision)->toBe(1)
        ->and($prediction->calculationRun->release->is($release))->toBeTrue()
        ->and($prediction->calculationRun->inputSnapshot->pregame_safety_status)->toBe('verified')
        ->and(data_get($prediction->calculationRun->inputSnapshot->inputs, 'home.elo'))->toBe(1600)
        ->and(data_get($prediction->calculationRun->inputSnapshot->inputs, 'away.metrics.net_rating'))->toBe(-6)
        ->and((float) $homeMoneyline->probability)->toBeGreaterThan(0.5)
        ->and((float) $homeSpread->projected_line)->toBeLessThan(0)
        ->and((float) $total->projected_line)->toBeGreaterThan(100)
        ->and(data_get($prediction->output_metadata, 'market_conventions.spread'))->toBe('sportsbook_home_line')
        ->and($summary?->id)->toBe($prediction->public_id)
        ->and($summary?->predictedSpread)->toBe((float) $homeSpread->projected_line)
        ->and($summary?->homeWinProbability)->toBe((float) $homeMoneyline->probability)
        ->and(LegacyWnbaPrediction::query()->count())->toBe(0);
});

it('registers and generates WNBA canonical predictions idempotently through explicit commands', function () {
    $fixture = canonicalWnbaFixture();

    $this->artisan('wnba:register-calculation-release', [
        '--effective-at' => now()->subMinute()->toIso8601String(),
    ])->expectsOutputToContain('approved')->assertSuccessful();

    $this->artisan('wnba:generate-canonical-predictions', [
        '--game' => $fixture['game']->getKey(),
    ])->expectsOutputToContain('1 succeeded, 0 failed')->assertSuccessful();

    $this->artisan('wnba:generate-canonical-predictions', [
        '--game' => $fixture['game']->getKey(),
    ])->expectsOutputToContain('1 succeeded, 0 failed')->assertSuccessful();

    expect(CalculationRelease::query()->count())->toBe(1)
        ->and(EventInputSnapshot::query()->count())->toBe(1)
        ->and(CalculationRun::query()->count())->toBe(1)
        ->and(CanonicalPrediction::query()->count())->toBe(1)
        ->and(PredictionMarket::query()->count())->toBe(4)
        ->and(LegacyWnbaPrediction::query()->count())->toBe(0);
});

it('records final results and canonical evaluations idempotently without mutating prediction outputs', function () {
    $fixture = canonicalWnbaFixture();
    app(WnbaCalculationReleaseRegistrar::class)->register(
        effectiveAt: now()->subMinute()->toImmutable(),
    );
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']);
    $outputHash = $prediction->output_hash;

    $this->travel(2)->days();
    $fixture['game']->update([
        'status' => 'STATUS_FINAL',
        'home_score' => 91,
        'away_score' => 82,
        'game_clock' => '0:00',
    ]);

    $this->artisan('wnba:evaluate-canonical-predictions', [
        '--game' => $fixture['game']->getKey(),
    ])->expectsOutputToContain('1 evaluated, 0 skipped, 0 failed')->assertSuccessful();

    $this->artisan('wnba:evaluate-canonical-predictions', [
        '--game' => $fixture['game']->getKey(),
    ])->expectsOutputToContain('1 evaluated, 0 skipped, 0 failed')->assertSuccessful();

    $result = SportEventResult::query()->sole();
    $evaluation = PredictionEvaluation::query()->sole();

    expect($result->revision)->toBe(1)
        ->and($result->home_score)->toBe(91)
        ->and($result->away_score)->toBe(82)
        ->and($evaluation->canonicalPrediction->is($prediction))->toBeTrue()
        ->and($evaluation->eventResult->is($result))->toBeTrue()
        ->and($evaluation->evaluation_revision)->toBe(1)
        ->and($evaluation->prediction_table)->toBeNull()
        ->and($evaluation->prediction_id)->toBeNull()
        ->and(data_get($evaluation->actuals, 'home_margin'))->toBe(9)
        ->and(data_get($evaluation->errors, 'winner_correct'))->toBeTrue()
        ->and(data_get($evaluation->errors, 'brier_score'))->toBeFloat()
        ->and($prediction->fresh()->output_hash)->toBe($outputHash)
        ->and(LegacyWnbaPrediction::query()->count())->toBe(0);
});

it('appends corrected results and evaluation revisions instead of overwriting history', function () {
    $fixture = canonicalWnbaFixture();
    app(WnbaCalculationReleaseRegistrar::class)->register(
        effectiveAt: now()->subMinute()->toImmutable(),
    );
    app(GenerateCanonicalPrediction::class)->execute($fixture['game']);

    $this->travel(2)->days();
    $fixture['game']->update([
        'status' => 'STATUS_FINAL',
        'home_score' => 88,
        'away_score' => 80,
        'game_clock' => '0:00',
    ]);
    $this->artisan('wnba:evaluate-canonical-predictions', [
        '--game' => $fixture['game']->getKey(),
    ])->assertSuccessful();

    $fixture['game']->update(['home_score' => 89]);
    $this->artisan('wnba:evaluate-canonical-predictions', [
        '--game' => $fixture['game']->getKey(),
    ])->assertSuccessful();

    $results = SportEventResult::query()->orderBy('revision')->get();
    $evaluations = PredictionEvaluation::query()->orderBy('evaluation_revision')->get();

    expect($results)->toHaveCount(2)
        ->and($results[1]->supersedes->is($results[0]))->toBeTrue()
        ->and($evaluations)->toHaveCount(2)
        ->and($evaluations[1]->supersedes->is($evaluations[0]))->toBeTrue()
        ->and(data_get($evaluations[0]->actuals, 'home_score'))->toBe(88)
        ->and(data_get($evaluations[1]->actuals, 'home_score'))->toBe(89);

    $evaluations[0]->errors = [];
    expect(fn () => $evaluations[0]->save())
        ->toThrow(LogicException::class, 'Canonical prediction evaluations are immutable.');
});

it('serves WNBA predictions from stored canonical outputs when the reader cutover is enabled', function () {
    $fixture = canonicalWnbaFixture();
    app(WnbaCalculationReleaseRegistrar::class)->register(
        effectiveAt: now()->subMinute()->toImmutable(),
    );
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']);
    $homeMoneyline = $prediction->markets
        ->where('market_type', 'moneyline')
        ->where('selection', 'home')
        ->sole();
    $homeSpread = $prediction->markets
        ->where('market_type', 'spread')
        ->where('selection', 'home')
        ->sole();

    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    config()->set('prediction_lifecycle.canonical_reads.wnba', true);
    Sanctum::actingAs($user);

    $this->getJson('/api/v2/sports/wnba/predictions?season=2026')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.prediction_source', 'canonical')
        ->assertJsonPath('data.0.id', $prediction->public_id)
        ->assertJsonPath('data.0.game_id', $fixture['game']->getKey())
        ->assertJsonPath('data.0.sport_event_id', $fixture['event']->public_id)
        ->assertJsonPath('data.0.revision', 1)
        ->assertJsonPath('data.0.publication_state', 'published')
        ->assertJsonPath('data.0.home_win_probability', (float) $homeMoneyline->probability)
        ->assertJsonPath('data.0.predicted_spread', (float) $homeSpread->projected_line)
        ->assertJsonPath('data.0.audit_context.prediction_id', $prediction->public_id)
        ->assertJsonPath('data.0.audit_context.calculation_release_version', $prediction->model_version)
        ->assertJsonPath('data.0.value_signal', null);

    $this->getJson("/api/v2/sports/wnba/predictions/{$prediction->public_id}")
        ->assertOk()
        ->assertJsonPath('data.id', $prediction->public_id);

    $this->getJson("/api/v2/sports/wnba/games/{$fixture['game']->getKey()}/prediction")
        ->assertOk()
        ->assertJsonPath('data.id', $prediction->public_id);

    $this->getJson('/api/v2/sports/wnba/predictions/available-seasons')
        ->assertOk()
        ->assertJsonPath('data.0', 2026);

    $this->getJson('/api/v2/sports/wnba/predictions/available-dates?season=2026')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('reports concrete WNBA cutover gaps and becomes ready after safe generation', function () {
    $fixture = canonicalWnbaFixture();
    $readiness = app(WnbaCanonicalCutoverReadinessService::class);

    expect($readiness->report(2026))
        ->ready_for_cutover->toBeFalse()
        ->missing_safe_prediction_count->toBe(1);

    $this->artisan('wnba:report-canonical-cutover-readiness', [
        '--season' => 2026,
        '--fail-on-not-ready' => true,
    ])->assertFailed();

    app(WnbaCalculationReleaseRegistrar::class)->register(
        effectiveAt: now()->subMinute()->toImmutable(),
    );
    app(GenerateCanonicalPrediction::class)->execute($fixture['game']);

    expect($readiness->report(2026))
        ->ready_for_cutover->toBeTrue()
        ->safe_published_event_count->toBe(1)
        ->missing_safe_prediction_count->toBe(0)
        ->unsafe_published_revision_count->toBe(0)
        ->missing_evaluation_count->toBe(0);

    $this->artisan('wnba:report-canonical-cutover-readiness', [
        '--season' => 2026,
        '--json' => true,
        '--fail-on-not-ready' => true,
    ])->expectsOutputToContain('"ready_for_cutover": true')->assertSuccessful();
});
