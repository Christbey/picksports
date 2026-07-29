<?php

use App\Services\NFL\NflMlFeatureVectorBuilder;

it('builds a stable numeric pregame feature vector without downstream analysis data', function () {
    $features = app(NflMlFeatureVectorBuilder::class)->build(
        baseFeatures: [
            'home_elo' => 1542.5,
            'away_elo' => 1498.0,
            'neutral_site' => false,
            'game_id' => 99,
        ],
        modelMetadata: [
            'true_epa' => [
                'applied' => true,
                'home_net_epa' => 0.13,
                'away_net_epa' => '-0.04',
                'source' => 'pregame_metrics',
            ],
            'qb_form' => [
                'home' => ['qb_id' => 123, 'score' => 1.25, 'prior_attempts' => 190],
                'away' => ['score' => -0.5],
            ],
            'player_position_grades' => [
                'home' => ['groups' => ['QB' => ['grade' => 78.4]]],
                'away' => ['groups' => ['QB' => ['grade' => 70.1]]],
            ],
            'analysis_layer' => [
                'trust_score' => 88,
                'reason_codes' => ['strong_model_signal'],
            ],
            'adaptive_win_probability_calibration' => [
                'calibrated_win_probability' => 0.71,
            ],
            'shadow_inference' => [
                'challenger_output' => 0.73,
            ],
        ],
        marketContext: [
            'home_spread' => -3.5,
            'total' => 46.5,
            'bookmaker' => 'example',
        ],
    );

    expect($features)
        ->toHaveKey('home_elo', 1542.5)
        ->toHaveKey('away_elo', 1498.0)
        ->toHaveKey('neutral_site', 0)
        ->toHaveKey('true_epa__applied', 1)
        ->toHaveKey('true_epa__home_net_epa', 0.13)
        ->toHaveKey('qb_form__home__score', 1.25)
        ->toHaveKey('qb_form__home__prior_attempts', 190)
        ->toHaveKey('player_position_grades__home__groups__qb__grade', 78.4)
        ->toHaveKey('market__home_spread', -3.5)
        ->toHaveKey('market__total', 46.5)
        ->not->toHaveKey('game_id')
        ->not->toHaveKey('qb_form__home__qb_id')
        ->not->toHaveKey('analysis_layer__trust_score')
        ->not->toHaveKey('adaptive_win_probability_calibration__calibrated_win_probability')
        ->not->toHaveKey('shadow_inference__challenger_output');

    expect(array_keys($features))->toBe(collect(array_keys($features))->sort()->values()->all());
});

it('ignores list values and non-numeric labels', function () {
    $features = app(NflMlFeatureVectorBuilder::class)->build(
        ['week' => 3],
        [
            'contextual_factors' => [
                'risk_codes' => ['travel', 'short_rest'],
                'rest_days' => 5,
                'super_bowl_game_id' => 456,
                'kickoff_window' => 'early',
            ],
        ],
    );

    expect($features)->toBe([
        'contextual_factors__rest_days' => 5,
        'week' => 3,
    ]);
});
