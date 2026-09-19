<?php

use App\Services\CFB\Signals\CfbFootballSignalCatalog;

it('registers two hundred distinct executable football hypotheses without fitted coefficients', function () {
    $rules = CfbFootballSignalCatalog::all();
    expect($rules)->toHaveCount(200)
        ->and(array_unique(array_column($rules, 'label')))->toHaveCount(200)
        ->and(array_unique(array_map(fn ($rule) => json_encode($rule['condition']), $rules)))->toHaveCount(200);
    foreach ($rules as $id => $rule) {
        expect($rule['id'])->toBe($id)->and($rule['inputs'])->not->toBeEmpty()
            ->and($rule['market'])->toBeIn(['spread', 'total'])
            ->and($rule['coefficient'])->toBeNull()->and($rule['validation_status'])->toBe('registered_hypothesis')
            ->and(CfbFootballSignalCatalog::evaluate($rule, []))->toBeNull();
    }
});

it('evaluates cross-team thresholds and preserves missing values without an invented zero', function () {
    $rule = CfbFootballSignalCatalog::all()['scoring_pressure'];
    $features = ['team' => ['history' => ['current_season' => ['points_per_game' => 38]]],
        'opponent' => ['history' => ['current_season' => ['points_allowed_per_game' => 30]]]];
    expect(CfbFootballSignalCatalog::evaluate($rule, $features))->toBeTrue();
    $features['opponent']['history']['current_season']['points_allowed_per_game'] = null;
    expect(CfbFootballSignalCatalog::evaluate($rule, $features))->toBeNull();
    $features['opponent']['history']['current_season']['points_allowed_per_game'] = 0;
    expect(CfbFootballSignalCatalog::evaluate($rule, $features))->toBeFalse();
    $cross = CfbFootballSignalCatalog::all()['recent_points_per_game'];
    $features['team']['history']['last3']['points_per_game'] = 30;
    $features['team']['history']['prior_season']['points_per_game'] = 25;
    expect(CfbFootballSignalCatalog::evaluate($cross, $features))->toBeTrue();
});

it('adapts frozen evidence with minimum samples and correct team orientation', function () {
    $input = ['event' => ['week' => 3, 'neutral_site' => false],
        'signal_context' => ['home_spread' => -21, 'total' => 50, 'wind_speed_mph' => 25, 'is_indoor' => true],
        'historical_signals' => ['away' => ['rest_days' => ['value' => 7], 'windows' => [
            'current_season' => ['metrics' => ['points_per_game' => ['value' => 38, 'sample_games' => 2]]],
            'away' => ['metrics' => ['points_per_game' => ['value' => 30, 'sample_games' => 4]]],
        ]]],
        'away' => ['personnel' => ['components' => ['quarterback' => ['status' => 'unresolved', 'values' => ['observed_primary_passer_changed' => true]]]]]];
    $features = CfbFootballSignalCatalog::features($input, 'away');
    expect($features['context']['home'])->toBeFalse()->and($features['context']['spread'])->toBe(21.0)
        ->and($features['context']['rest_days'])->toBe(7)->and($features['context']['wind_speed_mph'])->toBeNull()
        ->and($features['team']['history']['current_season']['points_per_game'])->toBeNull()
        ->and($features['team']['history']['venue']['points_per_game'])->toBe(30.0)
        ->and($features['team']['personnel']['qb_changed'])->toBeNull();
});

it('uses verified personnel and qualified fourth quarter evidence only', function () {
    $input = ['home' => ['personnel' => ['components' => [
        'quarterback' => ['status' => 'verified', 'values' => ['observed_primary_passer_changed' => false]],
        'head_coach' => ['status' => 'verified', 'values' => ['current' => [['name' => 'New Coach']], 'prior' => [['name' => 'Old Coach']]]],
    ]], 'large_spread_evidence' => ['pace' => ['sample_games' => 3, 'plays_proxy_per_game' => 75],
        'late_game' => ['sample_games' => 4, 'entered_fourth_leading_20_plus_games' => 1, 'fourth_margin_when_leading_20_plus' => -7, 'fourth_quarter_margin' => -3]]]];
    $features = CfbFootballSignalCatalog::features($input, 'home');
    expect($features['team']['personnel']['qb_changed'])->toBeFalse()
        ->and($features['team']['personnel']['coach_changed'])->toBeTrue()
        ->and($features['team']['late']['plays'])->toBe(75)
        ->and($features['team']['late']['fourth_margin'])->toBe(-3)
        ->and($features['team']['late']['leading_fourth_margin'])->toBeNull();
});

it('uses a verified prior-season sack aggregate only for the season mean', function () {
    $inputs = ['event' => ['season' => 2026], 'home' => ['prior_metrics' => ['season_sack_evidence' => [
        'source' => 'cfbd_stats_season', 'season' => 2025, 'games' => 12, 'sacks_allowed' => 18,
    ]]]];
    $features = CfbFootballSignalCatalog::features($inputs, 'home');
    expect(data_get($features, 'team.history.prior_season.sacks_allowed_per_game'))->toBe(1.5)
        ->and(data_get($features, 'team.history.last3.sacks_allowed_per_game'))->toBeNull();
    $inputs['home']['prior_metrics']['season_sack_evidence']['season'] = 2026;
    expect(data_get(CfbFootballSignalCatalog::features($inputs, 'home'), 'team.history.prior_season.sacks_allowed_per_game'))->toBeNull();
});

it('uses explicit prior evidence for small current samples without inventing recent or venue history', function () {
    $input = ['event' => ['season' => 2026], 'historical_signals' => ['home' => ['windows' => [
        'current_season' => ['metrics' => ['yards_per_play' => ['value' => 9, 'sample_games' => 1]]],
        'prior_season' => ['metrics' => ['yards_per_play' => ['value' => 5, 'sample_games' => 12]]],
    ]]]];
    $f = CfbFootballSignalCatalog::features($input, 'home', 'early_season_prior_v1');
    expect(data_get($f, 'team.history.current_season.yards_per_play'))->toBe(6.0)
        ->and(data_get($f, 'team.history_support.current_season.yards_per_play.current_games'))->toBe(1)
        ->and(data_get($f, 'team.history.last3.yards_per_play'))->toBeNull()
        ->and(data_get($f, 'team.history.venue.yards_per_play'))->toBeNull();
    $input['historical_signals']['home']['windows']['prior_season']['metrics']['yards_per_play']['sample_games'] = 2;
    expect(data_get(CfbFootballSignalCatalog::features($input, 'home', 'early_season_prior_v1'), 'team.history.current_season.yards_per_play'))->toBeNull();
});

it('adapts original frozen season aggregates but rejects stale seasons', function () {
    $input = ['event' => ['season' => 2026], 'home' => ['metrics' => ['record_season' => 2025,
        'wins' => 8, 'losses' => 4, 'points_per_game' => 35, 'points_allowed_per_game' => 21]]];
    $f = CfbFootballSignalCatalog::features($input, 'home', 'early_season_prior_v1');
    expect(data_get($f, 'team.history.current_season.points_per_game'))->toBe(35.0)
        ->and(data_get($f, 'team.history_support.current_season.points_per_game.source'))->toBe('prior_season_only');
    $input['home']['metrics']['record_season'] = 2024;
    expect(data_get(CfbFootballSignalCatalog::features($input, 'home', 'early_season_prior_v1'), 'team.history.current_season.points_per_game'))->toBeNull();
});
