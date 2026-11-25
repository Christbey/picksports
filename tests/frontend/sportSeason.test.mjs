import assert from 'node:assert/strict';
import { test } from 'node:test';
import { currentSportSeason, defaultSportSeason } from '../../resources/js/lib/sportSeason.ts';

test('basketball uses the ending year and rolls over in July Central time', () => {
    for (const sport of ['cbb', 'wcbb', 'nba']) {
        assert.equal(currentSportSeason(sport, new Date('2026-11-24T18:00:00Z')), 2027);
        assert.equal(currentSportSeason(sport, new Date('2027-03-15T18:00:00Z')), 2027);
        assert.equal(currentSportSeason(sport, new Date('2026-07-01T04:59:59Z')), 2026);
        assert.equal(currentSportSeason(sport, new Date('2026-07-01T05:00:00Z')), 2027);
        assert.equal(currentSportSeason(sport, new Date('2029-11-24T18:00:00Z')), 2030);
    }
});

test('football retains the prior season through February; MLB and WNBA use calendar year', () => {
    for (const sport of ['nfl', 'cfb']) {
        assert.equal(currentSportSeason(sport, new Date('2027-02-01T18:00:00Z')), 2026);
        assert.equal(currentSportSeason(sport, new Date('2027-03-01T18:00:00Z')), 2027);
    }
    for (const sport of ['mlb', 'wnba']) {
        assert.equal(currentSportSeason(sport, new Date('2026-11-24T18:00:00Z')), 2026);
    }
});

test('defaults prefer the sport season over calendar year and future or stale data', () => {
    const date = new Date('2026-11-24T18:00:00Z');
    assert.equal(defaultSportSeason('cbb', [2026, 2028, 2027], date), '2027');
    assert.equal(defaultSportSeason('nba', [2026, 2027], date), '2027');
    assert.equal(defaultSportSeason('nfl', [2025, 2026, 2027], date), '2026');
    assert.equal(defaultSportSeason('cbb', [2025, 2026, 2028], date), '2026');
    assert.equal(defaultSportSeason('cbb', [], date), '');
});
