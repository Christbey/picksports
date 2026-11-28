<?php

use App\Services\Sports\Elo\EloFixedScale;

it('normalizes exact Elo decimal text without binary float hashing', function ($value, string $expected) {
    expect((new EloFixedScale)->rating($value))->toBe($expected);
})->with([[1500, '1500.0000'], ['1500.25', '1500.2500'], ['1500.25000', '1500.2500'],
    [-1.5, '-1.5000'], ['1.25e2', '125.0000'], ['1e-4', '0.0001'], ['-0.0000', '0.0000']]);

it('refuses unavailable lossy or out of capacity Elo numbers', function ($value) {
    expect(fn () => (new EloFixedScale)->rating($value))->toThrow(DomainException::class);
})->with([null, true, '', 'invalid', '1.23456', '100000000', INF, NAN, '1e99']);

it('normalizes exact finite bounded regression factors', function ($value, string $expected) {
    expect((new EloFixedScale)->factor($value))->toBe($expected);
})->with([[0, '0.0000000'], [1, '1.0000000'], ['0.3333333', '0.3333333'], [1e-7, '0.0000001']]);

it('refuses invalid regression factors at exact storage precision', function ($value) {
    expect(fn () => (new EloFixedScale)->factor($value))->toThrow(DomainException::class);
})->with([-0.1, 1.1, INF, NAN, '0.33333333', null, false]);
