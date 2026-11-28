<?php

use App\DataTransferObjects\ESPN\BaseballPlayData;
use App\DataTransferObjects\ESPN\BasketballPlayData;
use App\DataTransferObjects\ESPN\CbbPlayData;
use App\DataTransferObjects\ESPN\FootballPlayData;

dataset('scoreObservationPlayDtos', [FootballPlayData::class, BasketballPlayData::class, BaseballPlayData::class, CbbPlayData::class]);

it('keeps absent play scores unavailable without fabricating a scoreless observation', function (string $dto) {
    $row = $dto::fromEspnResponse(['id' => 'synthetic'], 0)->toArray();
    expect($row['home_score'])->toBeNull()->and($row['away_score'])->toBeNull();
})->with('scoreObservationPlayDtos');

it('preserves genuine zero and existing whole-number score forms', function (string $dto) {
    $row = $dto::fromEspnResponse(['id' => 'synthetic', 'homeScore' => 0, 'awayScore' => ' +12.00 '], 0)->toArray();
    expect($row['home_score'])->toBe(0)->and($row['away_score'])->toBe(12);
})->with('scoreObservationPlayDtos');

it('rejects present malformed play scores before model persistence', function (string $dto, mixed $score) {
    expect(fn () => $dto::fromEspnResponse(['id' => 'synthetic', 'homeScore' => $score, 'awayScore' => 0], 0))
        ->toThrow(InvalidArgumentException::class);
})->with('scoreObservationPlayDtos')->with([
    'negative' => -1, 'fractional number' => 1.5, 'fractional text' => '1.5',
    'bool' => true, 'array' => [[]], 'empty' => '', 'exponent' => '1e2',
    'nonfinite' => INF, 'SQL integer overflow' => 2147483648,
]);
