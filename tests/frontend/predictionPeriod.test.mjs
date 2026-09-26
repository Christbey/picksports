import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    currentSlateDate,
    footballSeason,
} from '../../resources/js/lib/predictionPeriod.ts';

test('uses today in the middle of the season instead of the opening week', () => {
    assert.equal(
        currentSlateDate(
            ['2026-08-29', '2026-09-26', '2026-10-03'],
            '2026-09-26',
        ),
        '2026-09-26',
    );
});
test('keeps the current slate after Saturday games, then rolls forward on Monday', () => {
    const dates = ['2026-10-03', '2026-09-26', '2026-09-24'];
    assert.equal(currentSlateDate(dates, '2026-09-27'), '2026-09-26');
    assert.equal(currentSlateDate(dates, '2026-09-28'), '2026-10-03');
});
test('handles preseason, offseason, empty schedules, and January postseason', () => {
    assert.equal(currentSlateDate(['2026-09-05'], '2026-08-01'), '2026-09-05');
    assert.equal(
        currentSlateDate(['2026-01-19', '2025-12-20'], '2026-03-01'),
        '2026-01-19',
    );
    assert.equal(currentSlateDate([], '2026-09-26'), null);
    assert.equal(footballSeason('2027-01-10'), 2026);
    assert.equal(footballSeason('2026-09-26'), 2026);
});
