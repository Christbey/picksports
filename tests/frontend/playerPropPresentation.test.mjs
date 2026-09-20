import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    formatPropStat,
    formatPropOdds,
    sideProbability,
    formatProbabilityEdge,
    propFreshness,
    formatPropTimestamp,
} from '../../resources/js/lib/playerPropPresentation.ts';

test('Under cards show recommended-side probabilities and percentage-point edge', () => {
    assert.equal(sideProbability(15.2, 'Under'), '84.8%');
    assert.equal(sideProbability(52.6, 'Under'), '47.4%');
    assert.equal(sideProbability(15.2, 'Over'), '15.2%');
    assert.equal(formatProbabilityEdge(37.4), '+37.4 pp');
});

test('missing and invalid numbers never become fabricated zeros or prices', () => {
    for (const value of [null, undefined, NaN, Infinity]) {
        assert.equal(formatPropStat(value), 'N/A');
        assert.equal(formatPropOdds(value), 'N/A');
        assert.equal(sideProbability(value, 'Under'), 'N/A');
        assert.equal(formatProbabilityEdge(value), 'N/A');
    }
    assert.equal(formatPropStat(0), '0');
    assert.equal(formatPropOdds(-110), '-110');
    assert.equal(formatPropOdds(150), '+150');
    assert.equal(formatPropOdds(0), 'N/A');
    assert.equal(sideProbability(101, 'Over'), 'N/A');
});

test('freshness uses original timestamp and expires strictly after 24 hours', () => {
    const fetched = '2026-09-19T10:10:00-05:00';
    const boundary = Date.parse(fetched) + 86_400_000;
    assert.equal(propFreshness(fetched, 24, boundary), 'fresh');
    assert.equal(propFreshness(fetched, 24, boundary + 1), 'stale');
    assert.equal(propFreshness(null, 24, boundary), 'unknown');
    assert.equal(propFreshness('invalid', 24, boundary), 'unknown');
    assert.equal(propFreshness(fetched, undefined, boundary), 'unknown');
    assert.equal(
        propFreshness(fetched, 24, Date.parse(fetched) - 1),
        'unknown',
    );
});

test('quote times include Central daylight or standard timezone', () => {
    assert.match(formatPropTimestamp('2026-09-20T15:10:00Z'), /10:10 AM CDT/);
    assert.match(formatPropTimestamp('2026-12-20T16:10:00Z'), /10:10 AM CST/);
    assert.equal(formatPropTimestamp(null), 'Unknown');
});
