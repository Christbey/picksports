<?php

use App\Services\NFL\PlayEpaDataService;

it('classifies epa-eligible plays and excludes administrative rows', function () {
    $service = new PlayEpaDataService;

    $eligible = (object) [
        'play_type' => 'Rush',
        'play_text' => 'K.Walker right guard to SEA 38 for 3 yards.',
        'down' => 1,
        'distance' => 10,
        'yards_to_endzone' => 65,
    ];

    $administrative = (object) [
        'play_type' => 'Official Timeout',
        'play_text' => 'Official Timeout at 04:52.',
        'down' => -1,
        'distance' => 0,
        'yards_to_endzone' => 0,
    ];

    $noPlayPenalty = (object) [
        'play_type' => 'Pass Reception',
        'play_text' => 'Penalty on SEA - No Play.',
        'down' => 2,
        'distance' => 8,
        'yards_to_endzone' => 60,
    ];

    expect($service->isEpaEligiblePlay($eligible))->toBeTrue()
        ->and($service->isEpaEligiblePlay($administrative))->toBeFalse()
        ->and($service->isEpaEligiblePlay($noPlayPenalty))->toBeFalse();
});

it('infers possession from player names in play text', function () {
    $service = new PlayEpaDataService;

    $homeTeamId = 101;
    $awayTeamId = 202;
    $map = [
        'walker' => $homeTeamId,
        'stafford' => $awayTeamId,
        'myers' => $homeTeamId,
        'williams' => $awayTeamId,
    ];

    $rushPossession = $service->inferPossessionFromPlayText(
        'K.Walker right guard to SEA 38 for 3 yards.',
        'Rush',
        $map,
        $homeTeamId,
        $awayTeamId
    );

    $kickoffPossession = $service->inferPossessionFromPlayText(
        'J.Myers kicks 62 yards from SEA 35 to LA 3.',
        'Kickoff',
        $map,
        $homeTeamId,
        $awayTeamId
    );

    $kickoffReturnPossession = $service->inferPossessionFromPlayText(
        'C.Williams returns kickoff to LA 25.',
        'Kickoff',
        $map,
        $homeTeamId,
        $awayTeamId
    );

    expect($rushPossession)->toBe($homeTeamId)
        ->and($kickoffPossession)->toBe($awayTeamId)
        ->and($kickoffReturnPossession)->toBe($awayTeamId);
});
