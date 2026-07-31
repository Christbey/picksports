<?php

use App\Support\CFB\CfbWeek;

it('calculates cfb week zero from the saturday before labor day week', function () {
    expect(CfbWeek::weekZeroDate(2024)->toDateString())->toBe('2024-08-24')
        ->and(CfbWeek::weekZeroDate(2025)->toDateString())->toBe('2025-08-23')
        ->and(CfbWeek::weekZeroDate(2026)->toDateString())->toBe('2026-08-29');
});

it('marks august 29 2026 opening games as week zero without shifting the following slate', function () {
    expect(CfbWeek::productWeekForGame(2026, 2, 1, '2026-08-29', '16:00:00'))->toBe(0)
        ->and(CfbWeek::productWeekForGame(2026, 2, 1, '2026-08-30', '02:00:00'))->toBe(0)
        ->and(CfbWeek::productWeekForGame(2026, 2, 1, '2026-09-03', '23:00:00'))->toBe(1)
        ->and(CfbWeek::productWeekForGame(2026, 2, 2, '2026-09-11', '23:00:00'))->toBe(2);
});

it('maps local week zero fetches back to espn week one', function () {
    expect(CfbWeek::espnWeekForProductWeek(2, 0))->toBe(1)
        ->and(CfbWeek::espnWeekForProductWeek(2, 1))->toBe(1)
        ->and(CfbWeek::espnWeekForProductWeek(3, 0))->toBe(0);
});

it('resolves current product week from the cfb calendar', function () {
    expect(CfbWeek::productWeekForDate(2026, '2026-08-29'))->toBe(0)
        ->and(CfbWeek::productWeekForDate(2026, '2026-09-03'))->toBe(1)
        ->and(CfbWeek::productWeekForDate(2026, '2026-09-11'))->toBe(2);
});
