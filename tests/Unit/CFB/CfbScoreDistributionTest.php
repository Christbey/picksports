<?php

use App\Services\CFB\Scoring\CfbOvertimeRules;
use App\Services\CFB\Scoring\CfbPossessionScorer;
use App\Services\CFB\Scoring\CfbScoreDistribution;

it('distinguishes winning from covering and preserves pushes and priced returns', function () {
    $d = new CfbScoreDistribution([
        ['home' => 35, 'away' => 14, 'probability' => .4],
        ['home' => 28, 'away' => 14, 'probability' => .3],
        ['home' => 14, 'away' => 21, 'probability' => .3],
    ], 1000);
    expect($d->market('moneyline', 'home')['win'])->toEqualWithDelta(.7, 1e-8)
        ->and($d->market('spread', 'home', -20.5)['win'])->toEqualWithDelta(.4, 1e-8)
        ->and($d->market('spread', 'home', -21)['push'])->toEqualWithDelta(.4, 1e-8)
        ->and($d->market('team_total', 'over', 28, 'home')['push'])->toEqualWithDelta(.3, 1e-8);
    $p = $d->priced('spread', 'home', -21, -110);
    expect($p['ev_per_unit'])->toEqualWithDelta(-.6, 1e-8);
    foreach ([-21, -14, 0, 14] as $line) {
        $home = $d->market('spread', 'home', $line);
        $away = $d->market('spread', 'away', -$line);
        expect($home['win'] + $away['win'] + $home['push'])->toEqualWithDelta(1, 1e-8);
    }
    expect(fn () => $d->market('team_total', 'over', 21))->toThrow(InvalidArgumentException::class);
});

it('derives internally consistent score expectations from deterministic possession samples', function () {
    $artifact = json_decode(file_get_contents(__DIR__.'/../../Fixtures/cfb-scoring-synthetic.json'), true);
    $game = ['home_id' => '1', 'away_id' => '2', 'kickoff' => '2026-09-26T18:00:00Z', 'season' => 2026, 'neutral' => false];
    $scorer = new CfbPossessionScorer;
    $first = $scorer->simulate($artifact, $game, 2000, 91);
    $second = $scorer->simulate($artifact, $game, 2000, 91);
    expect($first->scores)->toBe($second->scores);
    $summary = $first->summary();
    expect($summary['home_points'] + $summary['away_points'])->toEqualWithDelta($summary['total'], 1e-8)
        ->and($summary['home_points'] - $summary['away_points'])->toEqualWithDelta($summary['home_margin'], 1e-8);
    foreach ($first->scores as $score) {
        expect($score['home'])->not->toBe($score['away']);
    }
    expect($first->market('spread', 'home', -7)['win'])->toBeGreaterThanOrEqual($first->market('spread', 'home', -20.5)['win']);
});

it('uses the applicable overtime era and refuses fabricated opponent evidence', function () {
    expect(CfbOvertimeRules::state(2026, 3)['tries_only'])->toBeTrue()
        ->and(CfbOvertimeRules::state(2020, 3)['tries_only'])->toBeFalse()
        ->and(CfbOvertimeRules::validOutcome(CfbOvertimeRules::state(2026, 2), 7, 0))->toBeFalse();
    $artifact = json_decode(file_get_contents(__DIR__.'/../../Fixtures/cfb-scoring-synthetic.json'), true);
    expect(fn () => (new CfbPossessionScorer)->simulate($artifact, ['home_id' => '1', 'away_id' => 'missing', 'kickoff' => '2026-09-26', 'season' => 2026], 100))
        ->toThrow(DomainException::class, 'history');
});

it('matches the offline scoring distribution after FPI mixing and calibration within simulation tolerance', function () {
    $fixture = json_decode(file_get_contents(__DIR__.'/../../Fixtures/cfb-scoring-parity.json'), true);
    $distribution = (new CfbPossessionScorer)->simulate($fixture['artifact'], $fixture['game'], 50000, 123);
    $actual = $distribution->summary();
    expect($actual['home_points'])->toEqualWithDelta($fixture['expected']['home'], .5)
        ->and($actual['away_points'])->toEqualWithDelta($fixture['expected']['away'], .5)
        ->and($actual['home_win_probability'])->toEqualWithDelta($fixture['expected']['home_win'], .02)
        ->and($actual['probability_standard_error_max'])->toBeNull();
});
