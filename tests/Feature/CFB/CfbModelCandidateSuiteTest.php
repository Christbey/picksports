<?php

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Http\Resources\Api\V2\CanonicalSportPredictionResource;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\ResultRatingRun;
use App\Models\CFB\Team;
use App\Models\EventInputSnapshot;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\PredictionEvaluation;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\SportEventResult;
use App\Services\Api\V2\CanonicalPredictionPresentationService;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Api\V2\SportContextResolver;
use App\Services\CFB\Predictions\CfbCalculationReleaseDefinition;
use App\Services\CFB\Predictions\CfbCalculator;
use App\Services\CFB\Predictions\CfbCandidateEvidence;
use App\Services\CFB\Predictions\CfbCandidateReview;
use App\Services\CFB\Predictions\CfbForecastCandidates;
use App\Services\CFB\Predictions\CfbFrozenMarketEvidence;
use App\Services\CFB\Predictions\CfbOpponentAdjustedTotals;
use App\Services\CFB\Signals\CfbFootballSignalEvidence;
use App\Services\Predictions\CanonicalPredictionEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

function candidateGame(): array
{
    $asOf = CarbonImmutable::parse('2026-10-14 10:00:00', 'America/Chicago');
    test()->travelTo($asOf);
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'season' => 2026, 'season_type' => '2', 'starts_at' => $asOf->addHours(2)]);
    $home = Team::factory()->create(['school' => 'Home University', 'mascot' => 'Hawks', 'abbreviation' => 'HOM']);
    $away = Team::factory()->create(['school' => 'Away College', 'mascot' => 'Bears', 'abbreviation' => 'AWY']);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026, 'season_type' => 2,
        'home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-14', 'game_time' => '17:00:00', 'home_score' => 21, 'away_score' => 20, 'game_clock' => '0:00']);
    $snapshot = EventInputSnapshot::factory()->create(['sport' => 'cfb', 'sport_event_id' => $event->id,
        'captured_at' => $asOf, 'cutoff_at' => $event->starts_at]);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $snapshot->id, 'status' => 'succeeded']);
    $prediction = CanonicalPrediction::factory()->create(['sport' => 'cfb', 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'phase' => 'pregame', 'publication_state' => 'draft',
        'output_metadata' => ['market_conventions' => ['spread' => 'sportsbook_home_line']]]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'selection' => 'home', 'probability' => .75]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'selection' => 'away', 'probability' => .25]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'market_type' => 'spread', 'selection' => 'home', 'projected_line' => -6]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'market_type' => 'total', 'selection' => 'combined', 'projected_line' => 50]);
    $prediction->update(['publication_state' => 'published', 'published_at' => $asOf]);

    return compact('asOf', 'event', 'home', 'away', 'game', 'snapshot', 'prediction');
}

function candidateQuotes(array $f, array $override = []): array
{
    $snapshot = GameOddsSnapshot::query()->create(['sport' => 'cfb', 'game_table' => 'cfb_games', 'game_id' => $f['game']->id,
        'captured_at' => $f['asOf']->subMinutes(5), 'payload_hash' => hash('sha256', fake()->uuid()), 'odds_data' => []]);
    $rows = [];
    foreach ([['spreads', 'home', 'Home University', -3], ['spreads', 'away', 'Away College', 3], ['totals', 'over', null, 45], ['totals', 'under', null, 45]] as [$market, $side, $participant, $line]) {
        $rows[$side] = MarketQuote::query()->create([
            'sport' => 'cfb', 'source' => 'test', 'game_table' => 'cfb_games', 'game_id' => $f['game']->id,
            'game_odds_snapshot_id' => $snapshot->id, 'bookmaker_key' => 'testbook', 'market_key' => $market,
            'side' => $side, 'participant' => $participant, 'line' => $line, 'price' => -110, 'is_pregame' => true,
            'captured_at' => $f['asOf']->subMinutes(5), 'created_at' => $f['asOf']->subMinutes(4),
            'quote_hash' => hash('sha256', fake()->uuid()), 'metadata' => ['raw_market_key' => $market],
            ...$override,
        ]);
    }

    return $rows;
}

afterEach(fn () => test()->travelBack());

it('freezes only paired quotes available at capture and excludes later or mismatched quotes', function () {
    $f = candidateGame();
    $quotes = candidateQuotes($f);
    $service = app(CfbFrozenMarketEvidence::class);
    $market = $service->capture($f['game'], $f['asOf'], $f['event']->starts_at);
    expect($market['spread']['quote_id'])->toBe($quotes['home']->id)->and($market['spread']['line'])->toBe(-3.0)
        ->and($market['total']['line'])->toBe(45.0);
    candidateQuotes($f, ['line' => 99, 'captured_at' => $f['asOf']->addMinute(), 'created_at' => $f['asOf']->addMinute()]);
    expect($service->capture($f['game'], $f['asOf'], $f['event']->starts_at))->toBe($market);
    $quotes['away']->update(['bookmaker_key' => 'differentbook']);
    expect($service->capture($f['game'], $f['asOf'], $f['event']->starts_at)['spread'])->toBeNull();
    expect($service->capture($f['game'], $f['event']->starts_at, $f['event']->starts_at)['available'])->toBeFalse();
});

it('does not accept provider timestamps after capture or swapped team participants', function () {
    $f = candidateGame();
    $quotes = candidateQuotes($f);
    $quotes['home']->update(['metadata' => ['provider_observed_at' => $f['asOf']->addMinute()->toIso8601String()]]);
    $service = app(CfbFrozenMarketEvidence::class);
    expect($service->capture($f['game'], $f['asOf'], $f['event']->starts_at)['spread'])->toBeNull();
    $quotes['home']->update(['metadata' => [], 'participant' => 'Away College']);
    expect($service->capture($f['game'], $f['asOf'], $f['event']->starts_at)['spread'])->toBeNull();
});

it('settles both spread signs, totals, pushes, and exact quoted prices without approving bets', function () {
    $f = candidateGame();
    candidateQuotes($f);
    $market = app(CfbFrozenMarketEvidence::class)->capture($f['game'], $f['asOf'], $f['event']->starts_at);
    $service = app(CfbForecastCandidates::class);
    $home = $service->build(-6, 50, [], $market);
    $away = $service->build(6, 40, [], $market);
    $homeGrades = $service->grade($home, 21, 20);
    $awayGrades = $service->grade($away, 21, 20);
    expect($home['betting_approved'])->toBeFalse()->and($home['models']['independent']['home_line'])->toBe(-6.0)
        ->and($home['models']['market_half_blend']['home_line'])->toBe(-4.5)
        ->and($homeGrades['independent']['spread']['result'])->toBe('loss')
        ->and($homeGrades['independent']['over_under']['result'])->toBe('loss')
        ->and($awayGrades['independent']['spread']['selection'])->toBe('away')
        ->and($awayGrades['independent']['spread']['line'])->toBe(3.0)
        ->and($awayGrades['independent']['spread']['profit_units'])->toBe(100 / 110)
        ->and($awayGrades['independent']['over_under']['selection'])->toBe('under');
    expect($service->grade($home, 24, 21)['independent']['spread']['result'])->toBe('push');
});

it('learns a scoring prior and opponent effects from earlier results rather than a fixed 56', function () {
    $service = app(CfbOpponentAdjustedTotals::class);
    $rows = [];
    for ($i = 1; $i <= 120; $i++) {
        $rows[] = ['id' => $i, 'season' => 2025, 'home_team_id' => $i % 2 ? 1 : 2,
            'away_team_id' => $i % 2 ? 2 : 1, 'home_score' => 17, 'away_score' => 13];
    }
    $fit = $service->fit($rows, 2026);
    $forecast = $service->predict($fit, 1, 2);
    expect($forecast['league_total_prior'])->toBe(30.0)->and($forecast['total'])->toBeLessThan(40)
        ->and($forecast['pace_status'])->toBe('not_applied_without_verified_possession_evidence')
        ->and($service->fit(array_slice($rows, 0, 30), 2026)['status'])->toBe('insufficient_training_games')
        ->and($service->predict($fit, 1, 999))->toBeNull()
        ->and($service->fit([...$rows, ...$rows], 2026))->toBe($fit);
});

it('rejects historical candidate runs created after capture or containing the target game', function () {
    $f = candidateGame();
    $rows = [['id' => $f['game']->id, 'season' => 2026, 'home_team_id' => $f['home']->id, 'away_team_id' => $f['away']->id,
        'home_score' => 21, 'away_score' => 20, 'observed_at' => $f['asOf']->toIso8601String()]];
    $run = ResultRatingRun::query()->create(['season' => 2026, 'version' => CfbCandidateEvidence::VERSION,
        'input_hash' => hash('sha256', 'unsafe'), 'source_rows' => $rows, 'model' => [],
        'source_available_at' => $f['asOf'], 'created_at' => $f['asOf']->subMinute()]);
    $service = app(CfbCandidateEvidence::class);
    expect($service->historical($f['game'], $f['asOf'], $f['event']->starts_at)['status'])->toBe('missing_contemporaneous_history');
    expect(fn () => $run->update(['created_at' => $f['asOf']->addMinute()]))->toThrow(LogicException::class);
    expect($service->historical($f['game'], $f['asOf'], $f['event']->starts_at)['status'])->toBe('missing_contemporaneous_history');
    expect(ResultRatingRun::query()->count())->toBe(1);
});

it('appends market grading revisions idempotently without changing old grades or forecasts', function () {
    $f = candidateGame();
    candidateQuotes($f);
    $old = PredictionEvaluation::query()->create(['canonical_prediction_id' => $f['prediction']->id,
        'sport_event_id' => $f['event']->id, 'sport' => 'cfb', 'prediction_phase' => 'pregame',
        'evaluation_revision' => 1, 'scoring_version' => 'canonical-v1', 'evaluation_hash' => hash('sha256', 'old'),
        'actuals' => [], 'errors' => [], 'evaluated_at' => $f['asOf']]);
    $this->travel(4)->hours();
    $result = SportEventResult::factory()->create(['sport_event_id' => $f['event']->id, 'home_score' => 21, 'away_score' => 20]);
    $service = app(CanonicalPredictionEvaluator::class);
    $evaluation = $service->evaluate($f['prediction'], $result);
    expect($evaluation->evaluation_revision)->toBe(2)->and($evaluation->scoring_version)->toBe(CanonicalPredictionEvaluator::CFB_MARKET_SCORING_VERSION)
        ->and(data_get($evaluation->market_comparison, 'cfb_comparison.grades.independent.spread.result'))->toBe('loss')
        ->and($service->evaluate($f['prediction'], $result)->id)->toBe($evaluation->id)
        ->and($old->fresh()->market_comparison)->toBeNull()
        ->and($f['prediction']->fresh()->markets->firstWhere('market_type', 'spread')->projected_line)->toBe('-6.0000');
});

it('reports paired samples and exclusions read-only without pooling probability releases', function () {
    $f = candidateGame();
    candidateQuotes($f);
    $this->travel(4)->hours();
    $before = [CanonicalPrediction::count(), PredictionEvaluation::count(), ResultRatingRun::count(), MarketQuote::count()];
    $report = app(CfbCandidateReview::class)->report(2026);
    expect($report['games'])->toBe(1)->and($report['promotion']['allowed'])->toBeFalse()
        ->and($report['cohorts']['all']['paired_comparisons']['market_half_blend']['spread_absolute_error']['n'])->toBe(1)
        ->and($report['cohorts']['all']['paired_comparisons']['result_rating']['spread_absolute_error']['n'])->toBe(0)
        ->and($report['cohorts']['all']['paired_comparisons']['result_rating']['spread_absolute_error']['candidate_mae'])->toBeNull()
        ->and(count($report['probability_calibration']))->toBe(1)
        ->and([CanonicalPrediction::count(), PredictionEvaluation::count(), ResultRatingRun::count(), MarketQuote::count()])->toBe($before);
    Artisan::call('cfb:review-model-candidates', ['--season' => 2026]);
    expect(json_decode(Artisan::output(), true)['games'])->toBe(1);
});

it('presents CFB probability as an uncalibrated estimate without a confidence percentage', function () {
    $f = candidateGame();
    config()->set('prediction_lifecycle.canonical_reads.cfb', true);
    $context = app(SportContextResolver::class)->resolve('cfb');
    $loaded = app(CanonicalSportPredictionQuery::class)->find($context, $f['prediction']->public_id);
    $data = (new CanonicalSportPredictionResource($loaded, $context,
        app(CanonicalPredictionPresentationService::class)->forPrediction($loaded)))->resolve();
    expect($data['home_win_probability'])->toBe(.75)->and($data['confidence_score'])->toBeNull()
        ->and($data['confidence_context']['probability_status'])->toBe('uncalibrated_model_estimate');
});

it('captures both scoring and result candidates from the same earlier-game history', function () {
    $f = candidateGame();
    Game::factory()->count(100)->create(['season' => 2025, 'season_type' => 2,
        'home_team_id' => $f['home']->id, 'away_team_id' => $f['away']->id,
        'status' => 'STATUS_FINAL', 'home_score' => 17, 'away_score' => 13, 'game_date' => '2025-10-01']);
    Game::factory()->create(['season' => 2026, 'season_type' => 2,
        'home_team_id' => $f['home']->id, 'away_team_id' => $f['away']->id,
        'status' => 'STATUS_FINAL', 'home_score' => 99, 'away_score' => 90, 'game_date' => '2026-10-15']);
    $service = app(CfbCandidateEvidence::class);
    $evidence = $service->capture($f['game'], $f['asOf'], $f['event']->starts_at);
    expect($evidence['status'])->toBe('available')->and($evidence['source_games'])->toBe(100)
        ->and($evidence['opponent_adjusted_total']['league_total_prior'])->toBe(30.0)
        ->and($evidence['result_rating'])->not->toBeNull()
        ->and(ResultRatingRun::count())->toBe(1)
        ->and($service->capture($f['game'], $f['asOf'], $f['event']->starts_at))->toBe($evidence);
    $frozen = ResultRatingRun::first()->toArray();
    $this->travel(1)->hours();
    Game::where('season', 2025)->update(['home_score' => 90, 'updated_at' => now()]);
    expect($service->historical($f['game'], $f['asOf'], $f['event']->starts_at))->toBe($evidence)
        ->and(ResultRatingRun::first()->toArray())->toBe($frozen);
});

it('keeps independent markets and historical signal weights unchanged when shadow candidates are added', function () {
    $configuration = app(CfbCalculationReleaseDefinition::class)->configuration();
    $configuration['football_signals']['enabled'] = false;
    $release = new CalculationReleaseData('test', 'cfb', 'pregame', 'cfb-pregame-rules',
        'rules', '1.8.0', 'test', 'test', 'cfb-pregame-v1', $configuration);
    $inputs = ['event' => ['season' => 2026], 'home' => ['elo' => 1600, 'metrics' => null, 'injuries' => []],
        'away' => ['elo' => 1500, 'metrics' => null, 'injuries' => []]];
    $snapshot = fn ($i) => new EventInputSnapshotData('cfb-pregame-v1', $i, now()->toImmutable());
    $calculator = app(CfbCalculator::class);
    $baseline = $calculator->calculate($snapshot($inputs), $release);
    $inputs['candidate_suite'] = ['evidence' => ['status' => 'available', 'result_rating' => ['home_margin' => -9, 'total' => 40],
        'opponent_adjusted_total' => ['total' => 38]], 'market' => ['spread' => ['line' => -2], 'total' => ['line' => 45]]];
    $candidate = $calculator->calculate($snapshot($inputs), $release);
    expect(array_map(fn ($m) => $m->toArray(), $candidate->markets))->toBe(array_map(fn ($m) => $m->toArray(), $baseline->markets))
        ->and($candidate->metadata['comparison_forecasts']['status'])->toBe('shadow_only')
        ->and($candidate->metadata['comparison_forecasts']['models']['result_rating']['home_line'])->toBe(9.0);
    $oldConfig = $configuration;
    unset($oldConfig['candidate_suite']);
    expect(CfbFootballSignalEvidence::baselineHash($oldConfig))
        ->toBe(CfbFootballSignalEvidence::baselineHash($configuration));
});

it('documents optional CFB comparison fields without requiring them for other sports', function () {
    Artisan::call('api:v2-openapi-generate', ['--stdout' => true]);
    $schema = json_decode(Artisan::output(), true)['components']['schemas']['SportPrediction'];
    expect($schema['properties'])->toHaveKeys(['comparison_forecasts', 'market_evaluation', 'raw_home_win_probability', 'forecast_coordination'])
        ->and($schema['required'])->not->toContain('comparison_forecasts', 'forecast_coordination')
        ->and($schema['properties']['id']['type'])->toContain('string');
});

it('uses the same stored quote freshness window for UTC and local capture instants', function () {
    $f = candidateGame();
    $quotes = candidateQuotes($f, ['captured_at' => $f['asOf']->subHours(34)]);
    foreach ($quotes as $quote) {
        $quote->forceFill(['created_at' => $f['asOf']->subHours(34)])->save();
    }
    $service = app(CfbFrozenMarketEvidence::class);
    $local = $service->capture($f['game'], $f['asOf'], $f['event']->starts_at);
    $utc = $service->capture($f['game'], $f['asOf']->utc(), $f['event']->starts_at->utc());
    expect($local['available'])->toBeTrue()->and($utc)->toBe($local);
});
