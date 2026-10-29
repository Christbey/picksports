<?php

use App\Support\Sports\SeasonTypeCandidates;
use Tests\TestCase;

uses(TestCase::class);

it('preserves season filter aliases and their types', function (mixed $input, array $expected) {
    config(['test_sport.season' => [
        'types' => ['preseason' => 1, 'regular' => 2, 'postseason' => 3],
        'type_names' => ['preseason' => 'Preseason', 'regular' => 'Regular Season', 'postseason' => 'Postseason'],
    ]]);

    expect(SeasonTypeCandidates::resolve('test_sport', $input))->toBe($expected);
})->with([
    'integer code' => [2, [2, 'regular', 'Regular Season']],
    'string code' => ['2', ['2', 'regular', 'Regular Season']],
    'key' => ['regular', ['regular', 'Regular Season', 2]],
    'display name' => ['Regular Season', ['Regular Season']],
    'unknown code' => [9, [9]],
    'unknown key' => ['other', ['other']],
    'zero' => [0, [0]],
    'null' => [null, []],
    'empty string' => ['', []],
]);

it('keeps unconfigured sport values available as exact filters', function () {
    expect(SeasonTypeCandidates::resolve('unconfigured_sport', 2))->toBe([2])
        ->and(SeasonTypeCandidates::resolve('unconfigured_sport', 'regular'))->toBe(['regular']);
});
