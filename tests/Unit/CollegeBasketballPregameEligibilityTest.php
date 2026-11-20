<?php

use App\Models\WCBB\Team;
use App\Services\Predictions\Basketball\CollegeBasketballPregameEligibility;

it('classifies only explicit present placeholder team records as expected matchup holds', function (array $attributes) {
    $known = new Team(['school' => 'Known team', 'abbreviation' => 'KNOWN', 'espn_id' => '100']);
    $placeholder = new Team(['school' => 'Opponent', 'abbreviation' => 'OPP', 'espn_id' => '200', ...$attributes]);
    $eligibility = new CollegeBasketballPregameEligibility;

    expect($eligibility->hasPlaceholderTeams($known, $placeholder))->toBeTrue()
        ->and($eligibility->hasPlaceholderTeams($placeholder, $known))->toBeTrue()
        ->and($eligibility->teamsAreKnown($known, $placeholder))->toBeFalse();
})->with([
    'school TBD' => [['school' => ' TBD ']],
    'school TBD2' => [['school' => 'TBD2']],
    'abbreviation TBD' => [['abbreviation' => 'tbd']],
    'bracket placeholder' => [['abbreviation' => 'WFF']],
    'negative provider identity' => [['espn_id' => '-1']],
]);

it('keeps absent team relations distinct from expected placeholders', function () {
    $known = new Team(['school' => 'Known team', 'abbreviation' => 'KNOWN', 'espn_id' => '100']);
    $placeholder = new Team(['school' => 'TBD', 'abbreviation' => 'TBD', 'espn_id' => '-1']);
    $eligibility = new CollegeBasketballPregameEligibility;

    expect($eligibility->hasPlaceholderTeams(null, $known))->toBeFalse()
        ->and($eligibility->hasPlaceholderTeams($placeholder, null))->toBeFalse()
        ->and($eligibility->hasPlaceholderTeams(null, null))->toBeFalse()
        ->and($eligibility->hasPlaceholderTeams($known, $known))->toBeFalse()
        ->and($eligibility->teamsAreKnown(null, $known))->toBeFalse();
});
