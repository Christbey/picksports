<?php

use App\Services\NFL\NflPlayoffBracket;

it('locks a completed upset and reseeds the divisional round', function () {
    $field = array_map(fn (int $seed): array => ['team_id' => $seed, 'seed' => $seed], range(1, 7));
    $known = ['2:7' => ['home' => 2, 'neutral' => false, 'winner' => 7, 'conflict' => false]];
    $simulated = [];

    $result = (new NflPlayoffBracket)->resolve(['AFC' => $field], $known,
        function (array $first, array $second, bool $useHomeField, bool $firstIsHome) use (&$simulated): array {
            $simulated[] = [$first['seed'], $second['seed']];

            return $firstIsHome ? $first : $second;
        });

    expect($simulated)->toBe([[3, 6], [4, 5], [1, 7], [4, 3], [1, 3]])
        ->and($result['conference_champions'])->toBe(['AFC' => 1])
        ->and($result['unmatched_games'])->toBe([]);
});

it('uses the stored venue and returns unreconciled games', function (bool $neutralSite, bool $expectedHomeField) {
    $field = [['team_id' => 20, 'seed' => 2], ['team_id' => 70, 'seed' => 7]];
    $known = [
        '20:70' => ['home' => 70, 'neutral' => $neutralSite, 'winner' => null, 'conflict' => false],
        '10:40' => ['home' => 10, 'neutral' => false, 'winner' => 10, 'conflict' => false],
    ];

    $result = (new NflPlayoffBracket)->resolve(['AFC' => $field], $known,
        function (array $first, array $second, bool $useHomeField, bool $firstIsHome) use ($expectedHomeField): array {
            expect($useHomeField)->toBe($expectedHomeField)->and($firstIsHome)->toBeFalse();

            return $second;
        });

    expect($result['conference_champions'])->toBe(['AFC' => 70])
        ->and($result['unmatched_games'])->toBe(['10:40' => $known['10:40']]);
})->with([[true, false], [false, true]]);
