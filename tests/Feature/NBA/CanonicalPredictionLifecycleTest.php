<?php

use App\Actions\NBA\GenerateCanonicalPrediction;
use App\Actions\NBA\GeneratePrediction;
use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Jobs\Predictions\GeneratePredictionNarrative;
use App\Models\BetDecision;
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
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use App\Services\NBA\Predictions\NbaCalculationReleaseRegistrar;
use App\Services\NBA\Predictions\NbaCalculator;
use App\Services\NBA\Predictions\NbaCanonicalCutoverReadinessService;
use App\Services\NBA\Predictions\NbaInputSnapshotBuilder;
use App\Services\Predictions\CalculationReleaseManager;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Services\Predictions\PredictionDeliverySource;
use App\Support\SportsViewCache;
use Illuminate\Support\Facades\Bus;
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

    $cache = app(SportsViewCache::class);
    $cache->remember(SportsViewCache::SEGMENT_PREDICTIONS_INDEX, 'retirement-publication', 600, fn () => 'old');
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']);
    expect($cache->remember(SportsViewCache::SEGMENT_PREDICTIONS_INDEX, 'retirement-publication', 600, fn () => 'new'))->toBe('new');
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

    expect($readiness->report(2026)['ready_for_cutover'])->toBeFalse()
        ->and($readiness->report(2026)['readiness_blockers'])->toContain('established_model_release_required');

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

it('queues source-correct narratives after native publication when the legacy writer is retired', function () {
    Bus::fake();
    config(['prediction_lifecycle.canonical_pipeline.nba' => true, 'prediction_lifecycle.canonical_reads.nba' => true]);
    $fixture = canonicalNbaFixture();
    app(NbaCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']);
    Bus::assertDispatched(GeneratePredictionNarrative::class,
        fn ($job) => $job->predictionModelClass === CanonicalPrediction::class && $job->predictionId === $prediction->id && $job->sport === 'nba');
});

it('preserves the coverage repair suppression of narrative work', function () {
    Bus::fake();
    config(['prediction_lifecycle.canonical_pipeline.nba' => true, 'prediction_lifecycle.canonical_reads.nba' => true]);
    $fixture = canonicalNbaFixture();
    app(NbaCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    app(GenerateCanonicalPrediction::class)->execute($fixture['game'], trigger: 'coverage');
    Bus::assertNotDispatched(GeneratePredictionNarrative::class);
});

it('transports the established NBA model exactly using frozen configuration without legacy writes or public approvals', function () {
    config(['prediction_lifecycle.canonical_pipeline.nba' => true, 'prediction_lifecycle.canonical_reads.nba' => true]);
    $fixture = canonicalNbaFixture();
    $expected = app(GeneratePrediction::class)->preview($fixture['game']->fresh());
    $release = app(NbaCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    config(['nba.elo.home_court_advantage' => -1000]);
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']->fresh());
    expect((float) $prediction->markets->firstWhere('market_type', 'spread')->projected_line)->toBe(-(float) $expected['predicted_spread'])
        ->and((float) $prediction->markets->firstWhere('market_type', 'total')->projected_line)->toBe((float) $expected['predicted_total'])
        ->and((float) $prediction->markets->first(fn ($m) => $m->market_type === 'moneyline' && $m->selection === 'home')->probability)->toBe((float) $expected['win_probability'])
        ->and($prediction->output_metadata['forecast_source'])->toBe('frozen_nba_model_v1')
        ->and($release->input_schema_version)->toBe('nba-pregame-v2')
        ->and(config('nba.elo.home_court_advantage'))->toBe(-1000)
        ->and(LegacyNbaPrediction::count())->toBe(0)
        ->and(BetDecision::where('is_public', true)->count())->toBe(0);
    config(['prediction_lifecycle.canonical_reads.nba' => true]);
    $context = app(PredictionDeliverySource::class)->forGame($fixture['game']->fresh(), 'nba');
    expect(app(GameBettingRecommendationService::class)->forPrediction($context))->toBe([]);
});

it('requires the selected effective full-model release and complete bounded coverage for NBA readiness', function () {
    $fixture = canonicalNbaFixture();
    $readiness = app(NbaCanonicalCutoverReadinessService::class);
    expect($readiness->report(2026)['readiness_blockers'])->toContain('canonical_release_unavailable');
    $release = app(NbaCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    expect($readiness->report(2026)['missing_full_model_forecast_game_ids'])->toBe([$fixture['game']->id]);
    app(GenerateCanonicalPrediction::class)->execute($fixture['game']->fresh());
    $report = $readiness->report(2026);
    expect($report['read_transport_ready'])->toBeTrue()->and($report['ready_for_cutover'])->toBeFalse()
        ->and($report['readiness_blockers'])->toBe(['canonical_pipeline_disabled']);
    config(['prediction_lifecycle.canonical_pipeline.nba' => true]);
    expect($readiness->report(2026)['ready_for_cutover'])->toBeTrue();
    $release->forceFill(['status' => 'invalidated'])->save();
    expect($readiness->report(2026)['read_transport_ready'])->toBeFalse()
        ->and($readiness->report(2026)['readiness_blockers'])->toContain('canonical_release_unavailable');
});

it('refuses missing NBA full-model projections instead of falling back to smaller rules', function (string $field) {
    $fixture = canonicalNbaFixture();
    $release = app(NbaCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $releaseData = CalculationReleaseData::fromModel($release);
    $snapshot = app(NbaInputSnapshotBuilder::class)->build($fixture['event']->fresh(), $releaseData);
    $inputs = $snapshot->inputs;
    $inputs['established_model']['outputs'][$field] = null;
    $broken = new EventInputSnapshotData($snapshot->schemaVersion, $inputs, $snapshot->capturedAt);
    expect(fn () => app(NbaCalculator::class)->calculate($broken, $releaseData))->toThrow(PredictionLifecycleException::class);
})->with(['predicted_spread', 'predicted_total', 'win_probability']);

it('holds NBA cutover for future ambiguous incompatible releases and an empty slate', function (string $variant) {
    $fixture = canonicalNbaFixture();
    if ($variant === 'incompatible') {
        CalculationRelease::factory()->approved()->create(['sport' => 'nba', 'phase' => 'pregame', 'input_schema_version' => 'unsupported', 'effective_at' => now()->subMinute()]);
    } else {
        app(NbaCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: ($variant === 'future' ? now()->addDay() : now()->subMinute())->toImmutable());
    }
    if ($variant === 'ambiguous') {
        CalculationRelease::factory()->approved()->create(['sport' => 'nba', 'phase' => 'pregame', 'effective_at' => now()->subMinute()]);
    } elseif ($variant === 'empty') {
        $fixture['event']->forceFill(['starts_at' => now()->addMonths(2)])->save();
    }
    $report = app(NbaCanonicalCutoverReadinessService::class)->report(2026);
    expect($report['ready_for_cutover'])->toBeFalse()->and($report['read_transport_ready'])->toBeFalse()
        ->and($report['readiness_blockers'])->toContain(match ($variant) {
            'incompatible' => 'canonical_release_incompatible', 'empty' => 'no_upcoming_slate', default => 'canonical_release_unavailable',
        });
})->with(['future', 'ambiguous', 'incompatible', 'empty']);

it('does not recalculate a frozen NBA projection during replay', function () {
    $fixture = canonicalNbaFixture();
    $release = app(NbaCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $releaseData = CalculationReleaseData::fromModel($release);
    $snapshot = app(NbaInputSnapshotBuilder::class)->build($fixture['event']->fresh(), $releaseData);
    $calculator = app(NbaCalculator::class);
    $before = $calculator->calculate($snapshot, $releaseData);
    config(['nba.prediction' => [], 'nba.elo.home_court_advantage' => 9999]);
    $this->mock(GeneratePrediction::class)->shouldNotReceive('preview');
    expect($calculator->calculate($snapshot, $releaseData))->toEqual($before);
});

it('reports unlinked NBA games instead of silently declaring complete coverage and renders readiness commands', function () {
    $fixture = canonicalNbaFixture();
    $fixture['game']->forceFill(['sport_event_id' => null])->save();
    $report = app(NbaCanonicalCutoverReadinessService::class)->report(2026);
    expect($report['unlinked_slate_game_ids'])->toBe([$fixture['game']->id])
        ->and($report['readiness_blockers'])->toContain('unlinked_slate_games');
    $this->artisan('nba:report-canonical-cutover-readiness', ['--season' => 2026])->assertSuccessful();
    $this->artisan('nba:report-canonical-cutover-readiness', ['--season' => 2026, '--json' => true, '--fail-on-not-ready' => true])->assertFailed();
});

it('bounds default NBA canonical generation while preserving explicit game targeting', function () {
    $near = canonicalNbaFixture();
    $farEvent = SportEvent::factory()->create(['sport' => 'nba', 'season' => 2026, 'season_type' => '2', 'starts_at' => now()->addDays(30), 'status' => 'STATUS_SCHEDULED']);
    $farGame = Game::factory()->create(['sport_event_id' => $farEvent->id, 'home_team_id' => $near['home']->id, 'away_team_id' => $near['away']->id, 'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_SCHEDULED', 'game_date' => now()->addDays(30)]);
    $far = ['event' => $farEvent, 'game' => $farGame];
    app(NbaCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $this->artisan('nba:generate-canonical-predictions', ['--season' => 2026])->assertSuccessful();
    expect(CanonicalPrediction::where('sport_event_id', $near['event']->id)->count())->toBe(1)
        ->and(CanonicalPrediction::where('sport_event_id', $far['event']->id)->count())->toBe(0);
    $this->artisan('nba:generate-canonical-predictions', ['--game' => $far['game']->id])->assertSuccessful();
    expect(CanonicalPrediction::where('sport_event_id', $far['event']->id)->count())->toBe(1);
});

it('does not replace future NBA source timestamps with a safe capture timestamp', function () {
    $fixture = canonicalNbaFixture();
    $fixture['home']->forceFill(['updated_at' => now()->addHours(2)])->save();
    app(NbaCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    expect(fn () => app(GenerateCanonicalPrediction::class)->execute($fixture['game']->fresh()))
        ->toThrow(PredictionLifecycleException::class);
    expect(CanonicalPrediction::where('publication_state', 'published')->count())->toBe(0);
});

it('accepts database-reordered frozen NBA configuration keys without relaxing the exact configuration contract', function () {
    $fixture = canonicalNbaFixture();
    $release = app(NbaCalculationReleaseRegistrar::class)->register('1.1.1', approve: false);
    $configuration = $release->configuration;
    $configuration['forecast']['configuration'] = array_reverse($configuration['forecast']['configuration'], true);
    $release->configuration = $configuration;
    $release->configuration_hash = app(CanonicalPayloadHasher::class)->hash($configuration);
    $release->save();
    app(CalculationReleaseManager::class)->approve($release, 'test', 'Database JSON ordering regression', now()->subMinute()->toImmutable());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game']->fresh());
    expect($prediction->output_metadata['forecast_source'])->toBe('frozen_nba_model_v1');
});

it('still rejects missing or unexpected frozen NBA configuration keys', function (string $variant) {
    $fixture = canonicalNbaFixture();
    $release = app(NbaCalculationReleaseRegistrar::class)->register('1.1.1', approve: false);
    $configuration = $release->configuration;
    if ($variant === 'missing') {
        unset($configuration['forecast']['configuration']['nba.season']);
    } else {
        $configuration['forecast']['configuration']['unexpected'] = [];
    }
    $release->configuration = $configuration;
    $release->save();
    expect(fn () => app(NbaInputSnapshotBuilder::class)->build($fixture['event'], CalculationReleaseData::fromModel($release)))
        ->toThrow(PredictionLifecycleException::class, 'NBA full-model configuration is unavailable.');
})->with(['missing', 'unexpected']);
