<?php

use App\Jobs\Predictions\GeneratePredictionNarrative;
use App\Models\BetDecision;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\MLB\PickCandidate;
use App\Models\MLB\Prediction;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\Api\V2\CanonicalPredictionPresentationService;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportPredictionReader;
use App\Services\MLB\MlbBettingSignalService;
use App\Services\MLB\MlbMarketAwareProjectionService;
use App\Services\MLB\MlbPredictionRecommendationService;
use App\Services\MLB\Picks\MlbDailyPickService;
use App\Services\MLB\Picks\MlbFirstFiveCandidateBuilder;
use App\Services\NBA\NbaBettingSignalService;
use App\Services\NFL\NflBettingSignalService;
use App\Services\Predictions\BettingForecastSource;
use App\Services\Predictions\PredictionNarrativeService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 28)->setTime(10, 0));
});
afterEach(fn () => $this->travelBack());

function nativeBettingWorkflowFixture(string $sport = 'mlb', bool $pitching = true): array
{
    config(["prediction_lifecycle.canonical_reads.{$sport}" => true]);
    $namespace = strtoupper($sport);
    $gameClass = "App\\Models\\{$namespace}\\Game";
    $teamClass = "App\\Models\\{$namespace}\\Team";
    $home = $teamClass::factory()->create(['location' => 'Home', 'name' => 'Team', 'abbreviation' => 'HME']);
    $away = $teamClass::factory()->create(['location' => 'Away', 'name' => 'Team', 'abbreviation' => 'AWY']);
    $markets = [];
    foreach (['h2h', 'h2h_1st_5_innings', 'h2h_1st_3_innings', 'spreads', 'spreads_1st_5_innings'] as $key) {
        $markets[] = ['key' => $key, 'outcomes' => [
            ['name' => 'Home Team', 'point' => -1.5, 'price' => -125],
            ['name' => 'Away Team', 'point' => 1.5, 'price' => 115],
        ]];
    }
    foreach (['totals' => 8.5, 'totals_1st_1_innings' => 0.5, 'totals_1st_5_innings' => 4.5, 'totals_1st_3_innings' => 2.5] as $key => $line) {
        $markets[] = ['key' => $key, 'outcomes' => [
            ['name' => 'Over', 'point' => $line, 'price' => -110],
            ['name' => 'Under', 'point' => $line, 'price' => -110],
        ]];
    }
    $start = now()->addHours(4)->utc();
    $event = SportEvent::factory()->create(['sport' => $sport, 'season' => 2026, 'starts_at' => $start]);
    $game = $gameClass::factory()->create([
        'sport_event_id' => $event->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'season_type' => '2', 'week' => 9, 'status' => 'STATUS_SCHEDULED',
        'game_date' => $start->toDateString(), 'game_time' => $start->toTimeString(),
        'odds_data' => ['home_team' => 'Home Team', 'away_team' => 'Away Team', 'bookmakers' => [['title' => 'Stored book', 'markets' => $markets]]],
        'odds_updated_at' => now(),
        ...($sport === 'mlb' ? ['probable_home_pitcher_espn_id' => '111', 'probable_away_pitcher_espn_id' => '222'] : []),
    ]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => $sport, 'phase' => 'pregame']);
    $snapshot = EventInputSnapshot::factory()->create([
        'sport' => $sport, 'sport_event_id' => $event->id,
        'inputs' => ['home' => ['elo' => 1540], 'away' => ['elo' => 1500],
            'pregame_market' => ['available' => true, 'consensus' => ['spread' => ['line' => -1.5], 'total' => ['line' => 8.5]]],
            ...($pitching ? ['pitching' => ['home' => ['elo' => 1600], 'away' => ['elo' => 1450]]] : [])],
    ]);
    $run = CalculationRun::factory()->create([
        'sport_event_id' => $event->id, 'event_input_snapshot_id' => $snapshot->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded',
    ]);
    $prediction = CanonicalPrediction::factory()->create([
        'sport' => $sport, 'sport_event_id' => $event->id, 'calculation_run_id' => $run->id,
        'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null,
        'detail_source' => null, 'detail_sport' => null, 'detail_id' => null,
    ]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'market_type' => 'moneyline', 'selection' => 'home', 'probability' => 0.72, 'confidence_score' => 74]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'market_type' => 'spread', 'selection' => 'home', 'projected_line' => -2.5]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'market_type' => 'total', 'selection' => 'combined', 'projected_line' => 9.5]);
    $prediction->update(['publication_state' => 'published', 'published_at' => now()]);

    return [$game, $prediction->refresh()];
}

it('builds sport signals from native forecasts without reading legacy predictions or inventing approvals', function (string $sport, string $serviceClass, string $futuresMethod, string $signalKey) {
    [$game] = nativeBettingWorkflowFixture($sport);
    $service = Mockery::mock($serviceClass, array_values((new ReflectionClass($serviceClass))->getConstructor() ? array_map(fn ($parameter) => app((string) $parameter->getType()), (new ReflectionClass($serviceClass))->getConstructor()->getParameters()) : []))->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive($futuresMethod)->andReturn([]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $payload = $service->signals(2026, now());
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($payload['prediction_source'])->toBe('canonical')
        ->and($payload[$signalKey][0]['game_id'])->toBe($game->id)
        ->and($payload['recommended_bets'])->toBe([])
        ->and(collect($queries)->contains(fn (array $query): bool => str_contains($query['query'], $sport.'_predictions')))->toBeFalse();
})->with([
    ['nba', NbaBettingSignalService::class, 'finalsSignals', 'moneyline'],
    ['nfl', NflBettingSignalService::class, 'superBowlSignals', 'winners'],
    ['mlb', MlbBettingSignalService::class, 'worldSeriesSignals', 'moneyline'],
]);

it('persists canonical MLB candidates and decisions with separate identity and unchanged line conventions', function () {
    [$game, $prediction] = nativeBettingWorkflowFixture();
    $legacy = Prediction::query()->create(['game_id' => $game->id, 'season' => 2026, 'predicted_spread' => -77, 'predicted_total' => 99, 'win_probability' => 0.01]);
    config(['mlb.picks.public_promotion_enabled' => true, 'mlb.picks.market_promotion' => array_fill_keys(['moneyline', 'run_line', 'total', 'first_inning', 'first_3', 'first_5', 'props'], true)]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = app(MlbDailyPickService::class)->generateForDate(now(), ['moneyline', 'run_line', 'total', 'first_inning', 'first_3', 'first_5'], season: 2026);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($result['candidate_count'])->toBeGreaterThanOrEqual(12)
        ->and(collect($queries)->contains(fn (array $query): bool => str_contains($query['query'], 'from "mlb_predictions"')))->toBeFalse();
    foreach ($result['candidates'] as $candidate) {
        expect($candidate->prediction_id)->toBeNull()
            ->and($candidate->canonical_prediction_id)->toBe($prediction->id)
            ->and($candidate->is_public)->toBeFalse()
            ->and($candidate->is_tracking_only)->toBeTrue()
            ->and($candidate->is_bet)->toBeFalse();
    }
    $moneyline = $result['candidates']->first(fn ($row) => $row->market_type === 'moneyline' && $row->side === 'home');
    expect((float) $moneyline->model_probability)->toBe(0.72)
        ->and($moneyline->canonicalPrediction->public_id)->toBe($prediction->public_id)
        ->and(data_get($moneyline->feature_snapshot, 'prediction_lineage.public_id'))->toBe($prediction->public_id)
        ->and(BetDecision::query()->where('source_id', $moneyline->id)->where('source_table', 'mlb_pick_candidates')->sole()->prediction_table)->toBe('predictions');
    $historical = PickCandidate::query()->create([
        'game_id' => $game->id, 'prediction_id' => $legacy->id, 'season' => 2026,
        'market_type' => 'moneyline', 'market_key' => 'h2h', 'side' => 'home', 'generated_at' => now(),
    ]);
    expect($historical->prediction->id)->toBe($legacy->id)
        ->and($historical->canonical_prediction_id)->toBeNull();
});

it('does not fall back to legacy MLB forecasts after native coverage is withdrawn', function () {
    [$game, $prediction] = nativeBettingWorkflowFixture();
    Prediction::query()->create(['game_id' => $game->id, 'season' => 2026, 'win_probability' => 0.99]);
    $prediction->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);

    expect(app(MlbDailyPickService::class)->generateForDate(now(), ['moneyline'], dryRun: true, season: 2026)['candidate_count'])->toBe(0);
});

it('does not fill absent frozen pitcher context with mutable current ratings', function () {
    [, $prediction] = nativeBettingWorkflowFixture(pitching: false);

    expect(collect(app(MlbFirstFiveCandidateBuilder::class)->build($prediction))->pluck('marketType')->unique()->values()->all())->toBe(['first_5_total']);
});

it('exposes approved native decisions through each sport signal service without changing their prices', function (string $sport, string $serviceClass, string $futuresMethod) {
    [$game, $prediction] = nativeBettingWorkflowFixture($sport);
    $decision = BetDecision::query()->create([
        'decision_run_id' => (string) Str::uuid(), 'decision_hash' => hash('sha256', Str::uuid()),
        'source_table' => 'predictions', 'source_id' => $prediction->id,
        'prediction_table' => 'predictions', 'prediction_id' => $prediction->id,
        'sport' => $sport, 'game_table' => $game->getTable(), 'game_id' => $game->id,
        'market_type' => 'moneyline', 'market_key' => 'h2h', 'side' => 'home',
        'price' => 120, 'edge' => 0.08, 'confidence' => 0.74, 'status' => 'released',
        'recommendation_label' => 'model_bet', 'is_public' => true, 'is_tracking_only' => false,
        'is_bet' => true, 'pregame_safe' => true, 'decided_at' => now(), 'game_start_at' => now()->addHours(4),
    ]);
    $arguments = array_map(fn ($parameter) => app((string) $parameter->getType()), (new ReflectionClass($serviceClass))->getConstructor()->getParameters());
    $service = Mockery::mock($serviceClass, $arguments)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive($futuresMethod)->andReturn([]);
    $payload = $service->signals(2026, now());

    expect($payload['recommended_bets'])->toHaveCount(1)
        ->and($payload['recommended_bets'][0]['bet_decision_id'])->toBe($decision->id)
        ->and($payload['recommended_bets'][0]['market_price'])->toBe(120.0)
        ->and($payload['recommended_bets'][0]['probability_edge'])->toBe(0.08);
})->with([
    ['nba', NbaBettingSignalService::class, 'finalsSignals'],
    ['nfl', NflBettingSignalService::class, 'superBowlSignals'],
    ['mlb', MlbBettingSignalService::class, 'worldSeriesSignals'],
]);

it('keeps frozen market conventions separate from mutable game odds and legacy metadata', function () {
    [$game] = nativeBettingWorkflowFixture('nfl');
    $forecast = app(BettingForecastSource::class)->forGames('nfl', [$game->id], 2026)->sole();

    expect($forecast->predictedSpread)->toBe(2.5)
        ->and($forecast->vegasSpread)->toBe(-1.5)
        ->and($forecast->marketHomeMargin)->toBe(1.5)
        ->and($forecast->marketTotal)->toBe(8.5)
        ->and($forecast->modelMetadata)->not->toHaveKey('analysis_layer');
    $history = app(BettingForecastSource::class)->historyForGames(collect([$game]), 'nfl')->sole();
    expect($history->marketHomeMargin)->toBe(1.5)->and($history->marketTotal)->toBe(8.5);
});

it('preserves the identity of explicitly supplied historical forecasts during cutover', function () {
    [$game, $native] = nativeBettingWorkflowFixture();
    $legacy = Prediction::query()->create([
        'game_id' => $game->id, 'season' => 2026, 'predicted_spread' => -7.5,
        'predicted_total' => 11.0, 'win_probability' => 0.2,
    ]);
    $historical = app(BettingForecastSource::class)->fromModel($legacy, 'mlb');
    $current = app(BettingForecastSource::class)->forGames('mlb', [$game->id], 2026)->sole();

    expect($historical->legacyPredictionId())->toBe($legacy->id)
        ->and($historical->canonicalPredictionId())->toBeNull()
        ->and($historical->predictedSpread)->toBe(-7.5)
        ->and($current->canonicalPredictionId())->toBe($native->id)
        ->and($current->legacyPredictionId())->toBeNull();
});

it('publishes canonical narratives and mlb recommendations without legacy reads or invented approval', function (string $sport) {
    [$game, $prediction] = nativeBettingWorkflowFixture($sport);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $narratives = app(PredictionNarrativeService::class);
    $narrative = $narratives->forSport($prediction, null, $sport, false);
    $hash = $narratives->inputHashForSport($prediction, null, $sport);
    if ($sport === 'mlb') {
        $recommendation = app(MlbPredictionRecommendationService::class)->forPrediction($prediction);
        expect($recommendation['public']['is_bet'])->toBeFalse()
            ->and($recommendation['public']['recommendation_type'])->toBe('no_play');
        $projection = app(MlbMarketAwareProjectionService::class)->forPrediction($prediction);
        expect($projection['home_model_probability'])->toBe(0.72)
            ->and($projection['is_bet'])->toBeFalse();
    }
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($narrative['prediction_public_id'])->toBe($prediction->public_id)
        ->and($narrative['betting_plan']['bet_pick'])->toBe('No bet / pass.')
        ->and($narrative['summary'])->toContain('72.0%')
        ->and($hash)->toHaveLength(64)
        ->and(collect($queries)->contains(fn ($query) => str_contains($query['query'], $sport.'_predictions')))->toBeFalse();
})->with(['nba', 'nfl', 'mlb']);

it('invalidates canonical narrative approval and preserves immutable prediction revisions', function () {
    [$game, $prediction] = nativeBettingWorkflowFixture();
    $service = app(PredictionNarrativeService::class);
    $before = $prediction->getAttributes();
    $oldHash = $service->inputHashForSport($prediction, null, 'mlb');
    $decision = BetDecision::query()->create([
        'decision_run_id' => (string) Str::uuid(), 'decision_hash' => hash('sha256', Str::uuid()),
        'source_table' => 'predictions', 'source_id' => $prediction->id,
        'prediction_table' => 'predictions', 'prediction_id' => $prediction->id,
        'sport' => 'mlb', 'game_table' => $game->getTable(), 'game_id' => $game->id,
        'market_type' => 'moneyline', 'market_key' => 'h2h', 'side' => 'home',
        'price' => 120, 'edge' => 0.08, 'confidence' => 0.74, 'status' => 'released',
        'recommendation_label' => 'model_bet', 'is_public' => true, 'is_tracking_only' => false,
        'is_bet' => true, 'pregame_safe' => true, 'decided_at' => now(), 'game_start_at' => now()->addHours(4),
    ]);
    $hash = $service->inputHashForSport($prediction, null, 'mlb');
    expect($hash)->not->toBe($oldHash);
    (new GeneratePredictionNarrative(CanonicalPrediction::class, $prediction->id, 'mlb'))->handle($service);
    $queried = app(CanonicalSportPredictionQuery::class)->queryForSport('mlb')->whereKey($prediction->id)->firstOrFail();
    expect($service->forSport($queried, null, 'mlb', false))->toBe($service->forSport($prediction, null, 'mlb', false));
    $key = 'prediction-narrative:canonical:'.$prediction->public_id.':'.$hash;
    expect(Cache::get($key)['approved_decision_ids'])->toBe([$decision->id])
        ->and($prediction->fresh()->getAttributes())->toBe($before);
    $recommendation = app(MlbPredictionRecommendationService::class)->forPrediction($prediction);
    expect($recommendation['public']['is_bet'])->toBeTrue()
        ->and($recommendation['public']['market_price'])->toBe(120)
        ->and($recommendation['promotion']['validated_for_promotion'])->toBeTrue()
        ->and($recommendation['promotion']['promotions_validated'])->toBeFalse();
    $presentation = app(CanonicalPredictionPresentationService::class)->forPrediction($prediction);
    expect($presentation->recommendation['public']['market_price'])->toBe(120)
        ->and($presentation->recommendation['public'])->not->toHaveKey('raw_edge')
        ->and($presentation->recommendation['public']['bet_decision_id'])->toBe($decision->id);
    $resource = app(SportPredictionReader::class)->resource(
        app(SportContextResolver::class)->resolve('mlb'), $prediction)->resolve(request());
    expect($resource['public_recommendation']['is_bet'])->toBeTrue()
        ->and($resource['recommendation']['public']['market_price'])->toBe(120)
        ->and($resource)->not->toHaveKey('narrative');
    $decision->update(['is_tracking_only' => true]);
    expect($service->forSport($prediction, null, 'mlb', false)['betting_plan']['bet_pick'])->toBe('No bet / pass.')
        ->and($service->inputHashForSport($prediction, null, 'mlb'))->not->toBe($hash);
    $prediction->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    expect($service->forSport($prediction, null, 'mlb', false)['summary'])->toBe('Forecast unavailable.');
    Cache::forget($key);
    (new GeneratePredictionNarrative(CanonicalPrediction::class, $prediction->id, 'mlb', true))->handle($service);
    expect(Cache::has($key))->toBeFalse();
});

it('selects canonical nba narrative jobs without dispatching legacy jobs', function () {
    [$game, $prediction] = nativeBettingWorkflowFixture('nba');
    Bus::fake();
    $this->artisan('nba:sync-prediction-narratives', ['--game' => $game->id])->assertSuccessful();
    Bus::assertDispatched(GeneratePredictionNarrative::class,
        fn ($job) => $job->predictionModelClass === CanonicalPrediction::class && $job->predictionId === $prediction->id);
    Bus::assertNotDispatched(App\Jobs\NBA\GeneratePredictionNarrative::class);
});

it('keeps old queued nba job identities attached to their original legacy record', function () {
    [$game, $native] = nativeBettingWorkflowFixture('nba');
    config(['nba.prediction.narrative.provider' => 'template']);
    $legacy = App\Models\NBA\Prediction::factory()->create(['game_id' => $game->id]);
    $before = $native->getAttributes();
    (new App\Jobs\NBA\GeneratePredictionNarrative($legacy->id, true))
        ->handle(app(PredictionNarrativeService::class));
    expect($legacy->fresh()->narrative_json)->toBeArray()
        ->and($native->fresh()->getAttributes())->toBe($before);
});
