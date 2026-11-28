<?php

use App\Support\InjuryImpactScorer;
use Tests\TestCase;

uses(TestCase::class);

it('recognizes explicit injured-reserve statuses like the existing out bucket', function (string $status) {
    config(['nfl.prediction.injury_epa_weighting_enabled' => false]);
    $scorer = new InjuryImpactScorer;
    expect($scorer->describe('nfl', 0, $status))->toBe($scorer->describe('nfl', 0, 'Out'));
})->with(['Injured Reserve', 'IR', 'Reserve - Injured Reserve']);

it('does not infer an IR status from letters inside an unrelated word', function (string $status) {
    config(['nfl.prediction.injury_epa_weighting_enabled' => false]);
    expect((new InjuryImpactScorer)->describe('nfl', 0, $status)['score'])->toBe(0.0);
})->with(['First evaluation', 'Required review', 'Without restrictions']);
