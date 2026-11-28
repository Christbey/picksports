<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\GameOddsSnapshot;
use App\Models\ModelRun;
use App\Models\NFL\Coach;
use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Models\NFL\TeamStat;
use App\Models\PredictionFeatureSnapshot;
use App\Models\User;
use App\Services\NFL\NflProSignalLayer;
use App\Support\NFL\NflGameStart;
use App\Support\NflBetRuleEngine;
use App\Support\NflReasonCodeCatalog;
use App\Support\NflValidatedSignalCombos;
use App\Support\Sports\GameDateTimePresenter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo('2026-11-28 06:00:00');
    Http::preventStrayRequests();
    Mail::fake();
    Notification::fake();
    Queue::fake();
    config(['app.timezone' => 'UTC']);
    foreach (['true_epa', 'preseason_signal', 'rolling_efficiency', 'opponent_adjusted_efficiency', 'total_environment', 'qb_form', 'line_matchup', 'contextual_factors', 'actual_weather', 'depth_chart_injuries', 'adaptive_win_probability_calibration', 'adaptive_point_calibration', 'automated_calibration_tweaks'] as $layer) {
        config(["nfl.predictions.{$layer}.enabled" => false]);
    }
    config(['nfl.predictions.market_blend.enabled' => false, 'nfl.predictions.analysis_layer.enabled' => true]);
    // Real configured bet rules and catalog remain unchanged. No scientific settings are persisted.
});

afterEach(fn () => $this->travelBack());

function heuristicReasonPreview(int $week = 15, string $time = '12:00:00'): array
{
    $home = Team::query()->create(['espn_id' => 'HEURISTIC-H', 'name' => 'Home Team', 'location' => 'Home City', 'abbreviation' => 'HH', 'conference' => 'AFC', 'division' => 'East']);
    $away = Team::query()->create(['espn_id' => 'HEURISTIC-A', 'name' => 'Away Team', 'location' => 'Away City', 'abbreviation' => 'HA', 'conference' => 'NFC', 'division' => 'West']);
    foreach ([[$home, 1800], [$away, 1400]] as [$team, $rating]) {
        EloRating::query()->create(['team_id' => $team->id, 'game_id' => null, 'season' => 2026, 'week' => 13, 'date' => '2026-11-21', 'elo_rating' => $rating, 'elo_change' => 0]);
    }
    $game = Game::query()->create([
        'espn_event_id' => 'heuristic-public-preview', 'espn_uid' => 'heuristic-public-preview-uid',
        'season' => 2026, 'season_type' => 'regular', 'week' => $week, 'game_date' => '2026-11-28', 'game_time' => $time,
        'home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_SCHEDULED', 'neutral_site' => false,
        'odds_updated_at' => now(), 'odds_data' => ['home_team' => 'Home Team', 'away_team' => 'Away Team', 'bookmakers' => [[
            'key' => 'synthetic_stored_book', 'markets' => [
                ['key' => 'spreads', 'outcomes' => [['name' => 'Home Team', 'point' => -7.0], ['name' => 'Away Team', 'point' => 7.0]]],
                ['key' => 'totals', 'outcomes' => [['name' => 'Over', 'point' => 46.5], ['name' => 'Under', 'point' => 46.5]]],
            ],
        ]]],
    ])->fresh(['homeTeam', 'awayTeam']);
    $before = ['predictions' => Prediction::query()->count(), 'snapshots' => PredictionFeatureSnapshot::query()->count(), 'runs' => ModelRun::query()->count()];
    $preview = app(GeneratePredictionFromHistoricalElo::class)->preview($game);
    $analysis = data_get($preview, 'model_metadata.analysis_layer');
    $public = is_array($analysis) ? NflValidatedSignalCombos::publicAnalysis($analysis) : null;
    $after = ['predictions' => Prediction::query()->count(), 'snapshots' => PredictionFeatureSnapshot::query()->count(), 'runs' => ModelRun::query()->count()];
    $evidence = ['game' => $game->getRawOriginal(), 'elo_rows' => EloRating::query()->orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all(), 'measured_rows' => ['team_metrics' => TeamMetric::query()->count(), 'team_stats' => TeamStat::query()->count(), 'coaches' => Coach::query()->count()], 'preview' => $preview, 'public_analysis' => $public, 'configured_rule_result' => app(NflBetRuleEngine::class)->evaluate((array) $analysis['reason_codes'], (array) $analysis['risk_flags'], (float) $analysis['trust_score'], (float) data_get($analysis, 'calculated_edge.spread_points'), (float) data_get($analysis, 'calculated_edge.total_points')), 'before' => $before, 'after' => $after];
    heuristicReasonTrace('preview', $evidence);
    expect($preview)->toBeArray()->and($analysis)->toBeArray()->and(data_get($analysis, 'applied'))->toBeTrue()
        ->and((float) data_get($preview, 'outputs.win_probability'))->toBeGreaterThan(.5)
        ->and((float) data_get($analysis, 'trust_score'))->toBeGreaterThanOrEqual(65)
        ->and(abs((float) data_get($analysis, 'calculated_edge.spread_points')))->toBeGreaterThanOrEqual(2)
        ->and($after)->toBe($before);
    Http::assertNothingSent();

    return ['game' => $game, 'preview' => $preview, 'analysis' => $analysis, 'public' => $public, 'measured_rows' => $evidence['measured_rows']];
}

function heuristicReasonTrace(string $case, array $payload): void
{
    $trace = getenv('OPS_NFL_HEURISTIC_TRACE');
    if (is_string($trace) && $trace !== '') {
        file_put_contents($trace, json_encode(['case' => $case, 'payload' => $payload], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    }
}

it('requires the stored kickoff window rather than late week alone for a primetime reason', function (int $week, string $time, bool $expected) {
    $result = heuristicReasonPreview($week, $time);
    expect(in_array('primetime_home_edge', $result['public']['reason_codes'], true))->toBe($expected);
})->with([
    'late week stored noon is not model primetime' => [15, '12:00:00', false],
    'earlier week stored noon positive' => [14, '12:00:00', false],
    'stored evening retains current model primetime positive' => [14, '19:20:00', true],
]);

it('does not present an unmeasured quality heuristic as actionable coaching evidence', function () {
    $result = heuristicReasonPreview();
    expect($result['measured_rows'])->toBe(['team_metrics' => 0, 'team_stats' => 0, 'coaches' => 0])
        ->and($result['public']['reason_codes'])->toContain('coaching_aggression_edge')
        ->and(data_get($result['public'], 'reason_code_metadata.coaching_aggression_edge.is_actionable'))->toBeFalse();
});

it('preserves explicitly labeled model and market assumptions without calling them independent measurements', function () {
    $result = heuristicReasonPreview();
    expect(data_get($result['public'], 'reason_code_metadata.strong_model_signal.source'))->toBe('model')
        ->and(data_get($result['public'], 'reason_code_metadata.strong_model_signal.is_actionable'))->toBeTrue()
        ->and(data_get($result['public'], 'reason_code_metadata.market_spread_edge_home.source'))->toBe('market')
        ->and(data_get($result['public'], 'reason_code_metadata.red_zone_efficiency_edge.is_actionable'))->toBeFalse();
});

it('records whether the heuristic is redundant in the actual configured key seven rule', function () {
    $result = heuristicReasonPreview();
    $analysis = $result['analysis'];
    $engine = app(NflBetRuleEngine::class);
    $args = [$analysis['risk_flags'], (float) $analysis['trust_score'], (float) data_get($analysis, 'calculated_edge.spread_points'), (float) data_get($analysis, 'calculated_edge.total_points')];
    $normal = $engine->evaluate($analysis['reason_codes'], ...$args);
    $without = $engine->evaluate(array_values(array_diff($analysis['reason_codes'], ['coaching_aggression_edge'])), ...$args);
    heuristicReasonTrace('real_default_rule_consumer_counterfactual', ['normal' => $normal, 'without_coaching' => $without, 'rules' => config('nfl.predictions.bet_rules.rules')]);
    expect(collect($normal['matched_rules'])->pluck('name')->all())->toContain('high_trust_key_7_model_edge')
        ->and($without)->toBe($normal);
});

it('labels model quality tokens as unmeasured diagnostic context through the public catalog', function (string $code, string $label) {
    $metadata = app(NflReasonCodeCatalog::class)->metadata($code);
    expect($metadata['code'])->toBe($code)
        ->and($metadata['label'])->toBe($label)
        ->and($metadata['source'])->toBe('model_context')
        ->and($metadata['is_diagnostic'])->toBeTrue()
        ->and($metadata['is_actionable'])->toBeFalse();
})->with([
    'red_zone_efficiency_edge' => ['red_zone_efficiency_edge', 'Model Quality/Total Context; Red-zone Efficiency Not Measured'],
    'third_down_defense_edge' => ['third_down_defense_edge', 'Model Quality Context; Third-down Defense Not Measured'],
    'field_position_edge' => ['field_position_edge', 'Model Quality Context; Field Position Not Measured'],
    'coaching_aggression_edge' => ['coaching_aggression_edge', 'Model Quality Context; Coaching Aggression Not Measured'],
    'fourth_down_decision_edge' => ['fourth_down_decision_edge', 'Model Quality Context; Fourth-down Decisions Not Measured'],
]);

it('exposes calendar heuristics as week context without invented motivation or special teams observations', function (int $week) {
    $r = heuristicReasonPreview($week);
    expect($r['measured_rows'])->toBe(['team_metrics' => 0, 'team_stats' => 0, 'coaches' => 0]);
    $metadata = $r['public']['reason_code_metadata'];
    expect($r['public']['reason_codes'])->toContain('must_win_motivation_edge')
        ->and($metadata['must_win_motivation_edge']['label'])->toBe('Late-season Week Context; Motivation Not Measured');
    if ($week >= 17) {
        expect($r['public']['reason_codes'])->toContain('lookahead_spot_risk', 'letdown_spot_risk', 'sandwich_spot_risk');
    }
})->with([16, 17]);

it('labels the actual stored UTC window separately from New York and Chicago display clocks', function (string $time, string $ny, string $chicago, bool $evening) {
    $r = heuristicReasonPreview(14, $time);
    $game = $r['game'];
    expect(NflGameStart::for($game)?->utc()->format('H:i:s'))->toBe($time);
    foreach (['America/New_York' => $ny, 'America/Chicago' => $chicago] as $zone => $expected) {
        config(['trends.timezones.nfl.display' => $zone]);
        expect(GameDateTimePresenter::forGame('nfl', $game)['game_time'])->toBe($expected);
    }
    expect(in_array('primetime_home_edge', $r['public']['reason_codes'], true))->toBe($evening);
    if ($evening) {
        expect($r['public']['reason_code_metadata']['primetime_home_edge']['label'])->toBe('Stored UTC Evening Home Model Context');
    }
})->with([
    ['19:20:00', '14:20:00', '13:20:00', true],
    ['01:20:00', '20:20:00', '19:20:00', false],
]);

it('keeps a known missing imported clock unknown rather than assigning the UTC evening window', function () {
    $r = heuristicReasonPreview(14, '00:00:00');
    $game = $r['game'];
    $odds = $game->odds_data;
    $odds['event_id'] = 'nflverse:synthetic-missing';
    $odds['commence_time'] = null;
    $odds['market_context'] = ['source' => 'nflverse_schedules', 'normalization_version' => 'nflverse_schedule_v2'];
    $game->update(['nflverse_game_id' => 'synthetic-missing', 'odds_data' => $odds]);
    expect(NflGameStart::for($game->fresh()))->toBeNull()
        ->and(GameDateTimePresenter::forGame('nfl', $game->fresh())['game_time'])->toBeNull();
    $preview = app(GeneratePredictionFromHistoricalElo::class)->preview($game->fresh());
    heuristicReasonTrace('missing_clock', ['game' => $game->fresh()->getRawOriginal(), 'preview' => $preview]);
    expect((array) data_get($preview, 'model_metadata.analysis_layer.reason_codes'))->not->toContain('primetime_home_edge');
});

it('describes scored team head to head evidence without inventing coach identity history', function () {
    $r = heuristicReasonPreview(14);
    $game = $r['game'];
    Game::factory()->create(['home_team_id' => $game->home_team_id, 'away_team_id' => $game->away_team_id,
        'season' => 2026, 'season_type' => 2, 'game_date' => '2026-11-01', 'game_time' => '18:00:00',
        'status' => 'STATUS_FINAL', 'home_score' => 21, 'away_score' => 14]);
    config(['nfl.predictions.contextual_factors.enabled' => true]);
    $preview = app(GeneratePredictionFromHistoricalElo::class)->preview($game->fresh());
    $analysis = (array) data_get($preview, 'model_metadata.analysis_layer');
    heuristicReasonTrace('team_h2h_without_coaches', ['preview' => $preview, 'coaches' => Coach::count()]);
    expect(Coach::count())->toBe(0)->and($analysis['reason_codes'])->toContain('coach_vs_opponent_history_edge')
        ->and($analysis['reason_code_metadata']['coach_vs_opponent_history_edge']['source'])->toBe('schedule_context');
});

it('distinguishes absent weather and observed weather while sharing only conservative suppression metadata', function (bool $measured) {
    $r = heuristicReasonPreview(14);
    $game = $r['game'];
    if ($measured) {
        $game->weather()->create(['provider' => 'synthetic', 'observed_at' => now(), 'is_indoor' => false, 'wind_speed_mph' => 20]);
    }
    $analysis = ['reason_codes' => [], 'risk_flags' => [], 'calculated_edge' => ['market_spread' => 7, 'market_total' => 46.5, 'spread_points' => 3, 'total_points' => $measured ? 0 : -4]];
    $layer = app(NflProSignalLayer::class)->build($game->fresh(), [], $analysis, 10, 42.5, .7);
    heuristicReasonTrace('prolayer_weather', ['measured' => $measured, 'layer' => $layer]);
    expect($layer['reason_codes'])->toContain('weather_total_suppression')
        ->and($layer['weather_roof']['wind_speed_mph'])->toBe($measured ? 20.0 : null);
    $m = app(NflReasonCodeCatalog::class)->metadata('weather_total_suppression');
    expect([$m['source'], $m['is_diagnostic'], $m['is_actionable']])->toBe(['model_context', true, false]);
    $actual = app(NflReasonCodeCatalog::class)->metadata('wind_under_signal');
    expect([$actual['source'], $actual['is_diagnostic'], $actual['is_actionable']])->toBe(['actual_weather', false, true]);
})->with([false, true]);

it('does not give an aggregate injury adjustment a quarterback replacement evidence source', function () {
    $r = heuristicReasonPreview(14);
    $layer = app(NflProSignalLayer::class)->build($r['game'], ['depth_chart_injuries' => ['spread_adjustment' => 2]], [], 10, 44, .7);
    heuristicReasonTrace('aggregate_injury_no_qb_receipt', ['layer' => $layer]);
    expect($layer['reason_codes'])->toContain('qb_replacement_value_edge');
    $m = app(NflReasonCodeCatalog::class)->metadata('qb_replacement_value_edge');
    expect([$m['source'], $m['is_diagnostic'], $m['is_actionable']])->toBe(['model_context', true, false]);
});

it('preserves quote-derived heuristic policy without inventing sharp or public-flow observations', function () {
    $r = heuristicReasonPreview(14);
    $game = $r['game'];
    foreach ([-3, -5, -4] as $i => $line) {
        $odds = $game->odds_data;
        $odds['bookmakers'][0]['markets'][0]['outcomes'][0]['point'] = $line;
        $odds['bookmakers'][0]['markets'][0]['outcomes'][1]['point'] = -$line;
        GameOddsSnapshot::create(['sport' => 'nfl', 'game_table' => 'nfl_games', 'game_id' => $game->id,
            'captured_at' => now()->subMinutes(30 - $i * 10), 'payload_hash' => hash('sha256', 'synthetic-quote-'.$i), 'odds_data' => $odds]);
    }
    $layer = app(NflProSignalLayer::class)->build($game, [], ['calculated_edge' => ['market_spread' => 4, 'market_total' => 46.5, 'spread_points' => 6, 'total_points' => 0]], 10, 46.5, .7);
    heuristicReasonTrace('quote_heuristics_no_flow', ['layer' => $layer]);
    expect($layer['reason_codes'])->toContain('steam_freshness', 'buyback_resistance');
    foreach (['sharp_line_move_signal', 'reverse_line_movement', 'steam_freshness', 'market_setter_slow_book_gap', 'buyback_resistance'] as $code) {
        $m = app(NflReasonCodeCatalog::class)->metadata($code);
        expect([$m['source'], $m['is_diagnostic'], $m['is_actionable']])->toBe(['market', false, true]);
    }
});

it('retains stored historical explanation metadata through the actual signals API without rewriting the prediction', function () {
    $r = heuristicReasonPreview(14);
    $game = $r['game'];
    $game->update(['season_type' => '2']);
    config(['prediction_lifecycle.canonical_reads.nfl' => false, 'subscriptions.enforce_tiers' => false]);
    $historical = ['code' => 'coaching_aggression_edge', 'label' => 'Historical stored label sentinel', 'source' => 'coaching', 'is_actionable' => true, 'is_diagnostic' => false];
    $analysis = ['bet_classification' => 'bet', 'trust_score' => 80, 'risk_flags' => [], 'reason_codes' => ['coaching_aggression_edge'],
        'reason_code_metadata' => ['coaching_aggression_edge' => $historical],
        'calculated_edge' => ['spread_points' => 3, 'market_spread' => 7, 'total_points' => 0, 'market_total' => 46.5]];
    $prediction = Prediction::create(['game_id' => $game->id, 'predicted_spread' => 10, 'predicted_total' => 46.5,
        'win_probability' => .7, 'confidence_score' => 80, 'model_metadata' => ['analysis_layer' => $analysis]]);
    $before = $prediction->fresh()->getRawOriginal();
    $this->actingAs(User::factory()->create());
    Cache::flush();
    $response = $this->getJson('/api/v2/sports/nfl/signals?season=2026&as_of_date=2026-11-28')->assertOk();
    expect(json_encode($response->json(), JSON_THROW_ON_ERROR))->toContain('Historical stored label sentinel')
        ->and($prediction->fresh()->getRawOriginal())->toBe($before);
    heuristicReasonTrace('stored_historical_api', ['response' => $response->json(), 'before' => $before, 'after' => $prediction->fresh()->getRawOriginal()]);
});

it('keeps token-derived direction metadata explicit without manufacturing an independently measured total direction', function () {
    $r = heuristicReasonPreview(14);
    $game = $r['game'];
    $game->awayTeam->update(['conference' => $game->homeTeam->conference, 'division' => $game->homeTeam->division]);
    config(['nfl.predictions.contextual_factors.enabled' => true]);
    $preview = app(GeneratePredictionFromHistoricalElo::class)->preview($game->fresh(['homeTeam', 'awayTeam']));
    $analysis = (array) data_get($preview, 'model_metadata.analysis_layer');
    $metadata = (array) data_get($analysis, 'reason_code_metadata.rivalry_under_signal');
    heuristicReasonTrace('token_direction_residual', ['preview' => $preview, 'metadata' => $metadata]);
    expect($analysis['reason_codes'])->toContain('rivalry_under_signal')
        ->and($metadata['label'])->toBe('Same-division Context; Under Direction Not Measured')
        ->and($metadata['direction'])->toBe('under')
        ->and($metadata['market_type'])->toBe('total');
    // These fields remain opaque-token classifications. The raw rule engine
    // does not consume catalog labels or metadata as independent measurements.
});
