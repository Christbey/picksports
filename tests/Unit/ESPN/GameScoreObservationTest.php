<?php

use App\DataTransferObjects\ESPN\GameData;
use App\DataTransferObjects\ESPN\MLBGameData;

function espnScoreObservationFixture(mixed $score): array
{
    return ['id' => 'synthetic-score', 'date' => '2026-09-01T18:00:00Z',
        'season' => ['year' => 2026, 'type' => 2], 'status' => ['type' => ['name' => 'STATUS_SCHEDULED']],
        'competitions' => [['competitors' => [
            ['homeAway' => 'home', 'team' => ['id' => '10'], 'score' => $score],
            ['homeAway' => 'away', 'team' => ['id' => '20'], 'score' => '0'],
        ]]]];
}

it('refuses malformed supplied game scores before integer conversion', function (string $class, mixed $score) {
    expect(fn () => $class::fromEspnResponse(espnScoreObservationFixture($score)))
        ->toThrow(InvalidArgumentException::class);
})->with([GameData::class, MLBGameData::class])->with([
    'overflow string' => '1e999', 'fractional number' => 21.5, 'fractional string' => '21.5',
    'hidden fractional string' => '1.00000000000000001', 'negative' => -1, 'boolean' => true,
    'unresolved object' => [['value' => 21]], 'infinite' => INF, 'NaN' => NAN,
    'outside SQL integer' => 2147483648,
]);

it('preserves observed zero, whole decimal and missing game scores distinctly', function (string $class) {
    foreach ([0, '0', '-0.0', 21, 21.0, '21.000'] as $score) {
        expect($class::fromEspnResponse(espnScoreObservationFixture($score))->homeScore)->toBe((int) $score);
    }
    expect($class::fromEspnResponse(espnScoreObservationFixture(null))->homeScore)->toBeNull();
    $missing = espnScoreObservationFixture(null);
    unset($missing['competitions'][0]['competitors'][0]['score']);
    expect($class::fromEspnResponse($missing)->homeScore)->toBeNull();
})->with([GameData::class, MLBGameData::class]);
