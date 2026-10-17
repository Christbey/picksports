<?php

use App\Services\CFB\Scoring\CfbPossessionScorer;
use App\Services\CFB\Scoring\CfbScoringChallenger;
use App\Services\CFB\Scoring\CfbScoringFeatures;

it('distinguishes absent injury coverage and unverified personnel from observed records', function () {
    $service = new CfbScoringFeatures;
    $context = $service->capture(['home' => ['injuries' => [], 'personnel' => ['components' => ['quarterback' => ['status' => 'unverified', 'values' => ['observed_primary_passer_changed' => true]]]]],
        'away' => ['injuries' => [['status' => 'out'], ['status' => 'questionable'], ['status' => 'out']]]]);
    expect($context['home']['out_records'])->toBeNull()->and($context['home']['qb_changed'])->toBeNull()
        ->and($context['away']['out_records'])->toBe(2)->and($context['away']['questionable_records'])->toBe(1);
    $features = $service->context(['context' => $context], 'home');
    expect($features['context:out_records:missing'])->toBe(1.0)->and($features)->not->toHaveKey('context:out_records');
});

it('models both teams pace and removes venue effects on neutral fields', function () {
    $service = new CfbScoringFeatures;
    $game = ['home_id' => '1', 'away_id' => '2', 'venue' => 'Stadium A', 'neutral' => false];
    expect($service->pace($game, 'home'))->toBe(['team:1' => 1.0, 'opponent:2' => 1.0])
        ->and($service->stadium($game, 'away'))->toBe(['venue:Stadium A:away' => 1.0]);
    $game['neutral'] = true;
    expect($service->stadium($game, 'home'))->toBe([]);
});

it('excludes market and betting-history rules from the efficiency challenger', function () {
    $catalog = ['efficiency' => ['inputs' => ['t.yards_per_play']], 'spread' => ['inputs' => ['context.spread']],
        'ats' => ['inputs' => ['r.ats_cover_rate']], 'total' => ['inputs' => ['context.total']]];
    expect(array_keys((new CfbScoringFeatures)->independentCatalog($catalog)))->toBe(['efficiency']);
});

it('matches the enhanced offline efficiency distribution including venue personnel and opponent pace', function () {
    $fixture = json_decode(file_get_contents(__DIR__.'/../../Fixtures/cfb-efficiency-parity.json'), true);
    $distribution = (new CfbPossessionScorer)->simulate($fixture['artifact'], $fixture['game'], 30000, 123);
    $actual = $distribution->summary();
    expect($actual['home_points'])->toEqualWithDelta($fixture['expected']['home'], .65)
        ->and($actual['away_points'])->toEqualWithDelta($fixture['expected']['away'], .65)
        ->and($actual['home_win_probability'])->toEqualWithDelta($fixture['expected']['home_win'], .025);
});

it('applies learned personnel effects only for the new artifact feature policy', function () {
    $fixture = json_decode(file_get_contents(__DIR__.'/../../Fixtures/cfb-scoring-synthetic.json'), true);
    $fixture['feature_policy'] = CfbScoringFeatures::POLICY;
    $fixture['models']['drive']['coefficients']['context:qb_changed'] = -1.5;
    $game = ['home_id' => '1', 'away_id' => '2', 'kickoff' => '2026-09-26T18:00:00Z', 'season' => 2026,
        'context' => ['home' => ['qb_changed' => 0], 'away' => ['qb_changed' => 0]]];
    $scorer = new CfbPossessionScorer;
    $before = $scorer->simulate($fixture, $game, 2000, 91)->summary();
    $game['context']['home']['qb_changed'] = 1;
    $after = $scorer->simulate($fixture, $game, 2000, 91)->summary();
    expect($after['home_points'])->toBeLessThan($before['home_points'] - 5);
    unset($fixture['feature_policy']);
    $legacy = $scorer->simulate($fixture, $game, 2000, 91)->summary();
    $game['context']['home']['qb_changed'] = 0;
    expect($scorer->simulate($fixture, $game, 2000, 91)->summary())->toBe($legacy);
});

it('freezes simulation precision without changing probability mass or integer outcomes', function () {
    $service = new CfbScoringChallenger;
    $frozen = $service->freeze(['summary' => ['home_points' => 25.240000000000002],
        'scores' => [['home' => 28, 'away' => 14, 'probability' => .29999999999999993],
            ['home' => 14, 'away' => 28, 'probability' => .7000000000000001]], 'status' => 'shadow']);
    expect($frozen['summary']['home_points'])->toBe(25.24)
        ->and($frozen['scores'][0]['home'])->toBe(28)
        ->and(array_sum(array_column($frozen['scores'], 'probability')))->toEqualWithDelta(1, 1e-10)
        ->and(json_encode($frozen))->not->toContain('000000000000002');
});
