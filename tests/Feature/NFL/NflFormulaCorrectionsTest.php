<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\NFL\NflFrozenSpreadReplay;
use App\Services\NFL\NflRushingMatchup;

function rushingProfile(float $off = 4.5, float $allowed = 4.5): array
{
    return ['games' => 4, 'off_rush_yards_per_attempt' => $off, 'def_rush_yards_allowed_per_attempt' => $allowed,
        'off_rush_attempts' => 100, 'def_rush_attempts' => 100, 'off_sack_allowed_rate' => 0.05, 'def_sack_rate' => 0.05];
}

it('increases the home rushing advantage for a better offense or weaker opposing defense', function () {
    $calculator = new NflRushingMatchup;
    $base = $calculator->edges(rushingProfile(), rushingProfile());
    $offense = $calculator->edges(rushingProfile(5.5), rushingProfile());
    $defense = $calculator->edges(rushingProfile(), rushingProfile(4.5, 5.5));
    expect($base['home'] - $base['away'])->toBe(0.0)
        ->and($offense['home'] - $offense['away'])->toBe(1.0)
        ->and($defense['home'] - $defense['away'])->toBe(1.0);
    $swapped = $calculator->edges(rushingProfile(), rushingProfile(5.5));
    expect($swapped['home'] - $swapped['away'])->toBe(-1.0);
});

it('rejects missing nonfinite or sampleless rushing profiles', function ($change) {
    expect((new NflRushingMatchup)->edges(array_replace(rushingProfile(), $change), rushingProfile()))->toBeNull();
})->with([
    [['off_rush_yards_per_attempt' => null]], [['def_rush_yards_allowed_per_attempt' => INF]],
    [['off_rush_attempts' => 0]], [['def_rush_attempts' => null]], [['off_rush_attempts' => INF]],
]);

it('corrects actual forecast direction without changing the existing total formula', function () {
    config(['nfl.predictions.line_matchup.enabled' => true]);
    $game = new Game(['home_team_id' => 1, 'away_team_id' => 2]);
    $run = function (array $home, array $away) use ($game): array {
        $action = Mockery::mock(GeneratePredictionFromHistoricalElo::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $action->shouldReceive('lineProfile')->with($game, 1)->andReturn($home);
        $action->shouldReceive('lineProfile')->with($game, 2)->andReturn($away);

        return (new ReflectionMethod($action, 'applyLineMatchupBlend'))->invoke($action, $game, 0.0, 0.5, 46.0);
    };
    $base = $run(rushingProfile(), rushingProfile());
    $weakerDefense = $run(rushingProfile(), rushingProfile(4.5, 5.5));
    expect($weakerDefense[0])->toBeGreaterThan($base[0])
        ->and($weakerDefense[0])->toEqualWithDelta(1.35 * 0.18, 0.0000001);
    // Original total signal: (-1 * .8) - (.2 * 14) = -3.6, capped to -3.
    expect($weakerDefense[2])->toEqualWithDelta(46 - 3 * 0.18, 0.0000001);
    $missing = $run(array_replace(rushingProfile(), ['off_rush_attempts' => 0]), rushingProfile());
    expect($missing)->toBe([0.0, 0.5, 46.0]);
});

it('retains precision through the injury stage including a zero adjustment', function () {
    config(['nfl.predictions.depth_chart_injuries.enabled' => true]);
    $game = new Game(['home_team_id' => 1, 'away_team_id' => 2, 'season' => 2026]);
    $counts = ['out' => 0.0, 'questionable' => 0.0, 'scoped_out' => 0, 'returned_before_game' => 0,
        'unknown_return_skipped' => 0, 'unknown_return_days' => 21, 'nflverse_rows' => 0];
    $action = Mockery::mock(GeneratePredictionFromHistoricalElo::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $action->shouldReceive('injuryCountsForTeam')->andReturn($counts);
    $result = (new ReflectionMethod($action, 'applyDepthChartInjuryAdjustments'))->invoke($action, $game, 3.549, 0.6, 45.551);
    expect($result)->toBe([3.549, 0.6, 45.551]);
    $metadata = (new ReflectionProperty($action, 'lastModelMetadata'))->getValue($action);
    expect($metadata['depth_chart_injuries']['raw_outputs'])->toBe(['spread' => 3.549, 'total' => 45.551]);
});

it('keeps raw outputs but uses published margins for analysis and a new model version', function () {
    $game = new Game(['season' => 2026, 'week' => 3, 'home_team_id' => 1, 'away_team_id' => 2,
        'game_date' => '2026-09-27', 'status' => 'STATUS_SCHEDULED']);
    $game->setRelation('homeTeam', new Team)->setRelation('awayTeam', new Team);
    $action = Mockery::mock(GeneratePredictionFromHistoricalElo::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $action->shouldReceive('getEloAtDate')->andReturn(1500.0);
    $action->shouldReceive('calculateForecast')->once()->andReturn([3.549, 0.612345, 45.551]);
    $action->shouldReceive('applyAnalysisLayer')->once()->with($game, 3.5, 45.6, 0.612345);
    $result = $action->preview($game);
    expect($result['outputs']['predicted_spread'])->toBe(3.5)
        ->and($result['outputs']['predicted_total'])->toBe(45.6)
        ->and($result['model_metadata']['raw_outputs']['predicted_spread'])->toBe(3.549)
        ->and($result['model_metadata']['raw_outputs']['predicted_total'])->toBe(45.551)
        ->and($result['model_version'])->toEndWith('-ats-v3')
        ->and($result['model_metadata']['numeric_policy']['selection_basis'])->toBe('published_one_decimal_margin_and_total');
});

it('isolates injury precision from the prior safeguards', function () {
    $fixtures = json_decode(file_get_contents(base_path('tests/Fixtures/nfl-frozen-spread-replay-2026.json')), true);
    $m = $fixtures[2]['metadata'];
    $replay = new NflFrozenSpreadReplay;
    // EPA fallback evidence is not needed for a rounding-only comparison.
    $result = $replay->replay($m, 6.5, null, 'injury_precision_only');
    expect($result['status'])->toBe('replayed')
        ->and($replay->replay($m, 6.5, null, 'batch_v2')['status'])->toBe('excluded')
        ->and($replay->replay($m, 6.5, null, 'unknown')['reason'])->toBe('unsupported_variant');
});

it('isolates the rushing correction and rejects unreproducible or missing profiles', function () {
    $m = json_decode(file_get_contents(base_path('tests/Fixtures/nfl-frozen-spread-replay-2026.json')), true)[0]['metadata'];
    $m['legacy']['spread'] = $m['blended']['spread'] = 0.0;
    foreach (['preseason_signal', 'rolling_efficiency', 'opponent_adjusted_efficiency', 'qb_form',
        'contextual_factors', 'depth_chart_injuries', 'adaptive_point_calibration', 'market_blend'] as $key) {
        $m[$key] = ['enabled' => false, 'applied' => false];
    }
    $m['line_matchup'] = ['enabled' => true, 'applied' => true, 'weight' => 0.18,
        'signal_spread' => -1.35, 'home' => rushingProfile(), 'away' => rushingProfile(4.5, 5.5)];
    $replay = new NflFrozenSpreadReplay;
    expect($replay->replay($m, -0.2, null, 'rushing_direction_only'))
        ->toMatchArray(['status' => 'replayed', 'original_margin' => -0.2, 'revised_margin' => 0.2]);
    $m['line_matchup']['signal_spread'] = -2.0;
    expect($replay->replay($m, -0.4, null, 'rushing_direction_only')['reason'])->toBe('legacy_line_signal_not_reproduced');
    unset($m['line_matchup']['home']['off_rush_attempts']);
    expect($replay->replay($m, -0.4, null, 'rushing_direction_only')['reason'])->toBe('missing_rushing_profile');
});

it('removes only historical context and preserves venue rest and coaching in shadow', function () {
    $m = json_decode(file_get_contents(base_path('tests/Fixtures/nfl-frozen-spread-replay-2026.json')), true)[0]['metadata'];
    $m['legacy']['spread'] = $m['blended']['spread'] = 0.0;
    foreach (['preseason_signal', 'rolling_efficiency', 'opponent_adjusted_efficiency', 'qb_form', 'line_matchup',
        'depth_chart_injuries', 'adaptive_point_calibration', 'market_blend'] as $key) {
        $m[$key] = ['enabled' => false, 'applied' => false];
    }
    $m['contextual_factors'] = ['enabled' => true, 'applied' => true, 'spread_adjustment' => 2.0,
        'home_away_strength' => ['spread_adjustment' => 0.2], 'schedule_spot' => ['spread_adjustment' => 0.3],
        'coaching_prior' => ['spread_adjustment' => 0.1], 'division_rivalry' => ['spread_adjustment' => 1.0],
        'matchup_records' => ['spread_adjustment' => 1.0], 'same_week_records' => ['spread_adjustment' => 1.0]];
    $result = (new NflFrozenSpreadReplay)->replay($m, 2.0, null, 'history_context_off_only');
    expect($result)->toMatchArray(['status' => 'replayed', 'revised_margin' => 0.6]);
    unset($m['contextual_factors']['schedule_spot']);
    expect((new NflFrozenSpreadReplay)->replay($m, 2.0, null, 'history_context_off_only')['status'])->toBe('excluded');
});
