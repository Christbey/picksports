<?php

use App\Services\Api\V2\CfbPredictionBoardPayload;

it('keeps rendered signal evidence and counters while removing hidden diagnostic documents', function () {
    $service = new CfbPredictionBoardPayload;
    $matched = ['id' => 'rule', 'side' => 'home', 'label' => 'Test', 'market' => 'spread', 'matched' => true,
        'contribution_points' => 0.0, 'status' => 'tracking', 'sample_games' => 20, 'input_support' => str_repeat('unused', 10000)];
    $full = ['id' => 'forecast', 'sport' => 'cfb', 'projection' => ['predicted_spread' => -2.58, 'home_win_probability' => 0.605874],
        'audit_context' => ['internal' => true], 'value_signal' => ['decision_notes' => ['Preserve'], 'best' => ['line' => -2.5],
            'football_signals' => ['catalog_count' => 400, 'applied' => 0, 'triggered' => 1, 'missing_inputs' => 0,
                'signals' => [$matched, [...$matched, 'id' => 'unmatched', 'matched' => false]]],
            'signal_contributions' => ['active_spread_signals' => 0, 'active_total_signals' => 0,
                'signals' => [['signal' => 'none', 'market' => 'total', 'active' => false, 'contribution_points' => 0, 'debug' => 'omit']]]]];
    $compact = $service->compact($full);
    expect($compact['projection'])->toBe($full['projection'])
        ->and($compact)->not->toHaveKey('audit_context')
        ->and($compact['value_signal']['best'])->toBe($full['value_signal']['best'])
        ->and($compact['value_signal']['decision_notes'])->toBe(['Preserve'])
        ->and($compact['value_signal']['football_signals']['signals'])->toHaveCount(1)
        ->and($compact['value_signal']['football_signals']['signals'][0])->not->toHaveKey('input_support')
        ->and($compact['value_signal']['football_signals']['signals'][0]['contribution_points'])->toBe(0.0)
        ->and($compact['value_signal']['football_signals']['catalog_count'])->toBe(400)
        ->and($compact['value_signal']['signal_contributions']['signals'])->toHaveCount(1);
});

it('sends live fields only when every displayed static value and page membership still match', function () {
    $service = new CfbPredictionBoardPayload;
    $page = ['current_page' => 1, 'per_page' => 25, 'total' => 1, 'last_page' => 1];
    $row = ['id' => 'forecast', 'sport' => 'cfb', 'game_id' => 1, 'status' => 'STATUS_IN_PROGRESS',
        'game' => ['id' => 1, 'status' => 'STATUS_IN_PROGRESS', 'home_score' => 7, 'away_score' => 0, 'clock' => '10:00'],
        'projection' => ['predicted_spread' => -2.58, 'home_win_probability' => 0.605874],
        'weather_forecast' => ['temperature_f' => 44], 'value_signal' => null,
        'live_predicted_total' => 53, 'live_win_probability' => 0.6];
    $initial = $service->response([$row], $page);
    $version = $initial['board']['version'];
    $updated = [...$row, 'game' => [...$row['game'], 'home_score' => 0, 'clock' => null], 'live_win_probability' => null];
    $live = $service->response([$updated], $page, $version);
    expect($live['board'])->toBe(['version' => $version, 'mode' => 'live'])
        ->and($live['data'][0])->not->toHaveKey('projection')
        ->and($live['data'][0]['game']['home_score'])->toBe(0)
        ->and($live['data'][0]['game']['clock'])->toBeNull()
        ->and($live['data'][0]['live_win_probability'])->toBeNull();
    foreach ([
        [...$row, 'projection' => ['predicted_spread' => 0]],
        [...$row, 'weather_forecast' => ['temperature_f' => 45]],
        [...$row, 'value_signal' => ['decision_status' => 'pass']],
        [...$row, 'actual_total' => 50, 'winner_correct' => false],
        [...$row, 'id' => 'new-revision'],
    ] as $changed) {
        $response = $service->response([$changed], $page, $version);
        expect($response['board']['mode'])->toBe('replace')
            ->and($response['data'][0])->toHaveKey('projection');
    }
    expect($service->response([], [...$page, 'total' => 0], $version)['board']['mode'])->toBe('replace');
    expect($service->response([$row], [...$page, 'total' => 2], $version)['board']['mode'])->toBe('replace');
});

it('matches the compact fixture used by the expanded Vue render parity test', function () {
    $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/cfb-board-delivery.json'), true, flags: JSON_THROW_ON_ERROR);
    expect((new CfbPredictionBoardPayload)->compact($fixture['full']))->toBe($fixture['compact']);
});
