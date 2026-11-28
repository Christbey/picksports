<?php

use App\Support\Sports\ExplicitOffsetInstant;

it('retains explicit UTC offset and fractional producer instants', function (string $raw, string $expected) {
    expect(ExplicitOffsetInstant::parse($raw)?->format('Y-m-d\TH:i:s.uP'))->toBe($expected);
})->with([
    ['2026-06-30T06:00:00Z', '2026-06-30T06:00:00.000000+00:00'],
    ['2026-06-30T01:00:00-05:00', '2026-06-30T06:00:00.000000+00:00'],
    ['2026-06-30T06:00:00.1+00:00', '2026-06-30T06:00:00.100000+00:00'],
]);

it('refuses relative unknown and noncalendar producer clocks', function (mixed $raw) {
    expect(ExplicitOffsetInstant::parse($raw))->toBeNull();
})->with([null, 0, '', 'now', '2026-06-30', '2026-06-30 06:00:00', '2026-02-30T06:00:00Z',
    '2026-06-30T24:00:00Z', '2026-06-30T06:00:60Z', '2026-06-30T06:00:00.1234567Z',
    '2026-06-30T06:00:00+24:00', '2026-06-30T06:00:00-00:00']);
