import assert from 'node:assert/strict';
import { test } from 'node:test';
const { liveSnapshotWarning } = await import(
    process.env.REVIEW_ORIGINAL === '1'
        ? '/private/tmp/picksports-merged-full-5gpj4mlj/resources/js/lib/nflLiveSnapshot.ts'
        : '../../resources/js/lib/nflLiveSnapshot.ts'
);
const { nflBoxScoreRows } = await import(
    process.env.REVIEW_ORIGINAL === '1'
        ? '/private/tmp/picksports-merged-full-5gpj4mlj/resources/js/lib/nflBoxScore.ts'
        : '../../resources/js/lib/nflBoxScore.ts'
);

const now = Date.parse('2026-11-27T12:00:00Z');
const snapshot = {
    game: { status: 'STATUS_IN_PROGRESS' },
    projection: {},
    source_updated_at: null,
    record_updated_at: '2026-11-27T11:59:59Z',
    source_clock_status: 'observation_unavailable',
};
test('a fresh local record cannot certify provider observation freshness', () => {
    assert.match(
        liveSnapshotWarning(snapshot, now, false),
        /Provider observation freshness is unavailable/,
    );
    assert.match(
        liveSnapshotWarning(
            { ...snapshot, source_updated_at: '2026-11-27T11:59:59Z' },
            now,
            false,
        ),
        /Provider observation freshness is unavailable/,
    );
});
test('unqualified stored record text never gains an inferred instant or age', () => {
    assert.match(
        liveSnapshotWarning(
            {
                ...snapshot,
                record_updated_at: null,
                record_updated_at_raw: '2026-11-27 05:55:00',
                record_clock_status: 'unverified_storage_clock',
            },
            now,
            false,
        ),
        /Provider observation freshness is unavailable/,
    );
});
for (const [made, attempts] of [
    [5, 1],
    [-1, 4],
    [1.5, 4],
]) {
    test(`contradictory count pair ${made}/${attempts} cannot display a percentage or win a comparison`, () => {
        const row = nflBoxScoreRows(
            { third_down_conversions: made, third_down_attempts: attempts },
            { third_down_conversions: 1, third_down_attempts: 4 },
        ).find((r) => r.label === '3rd Down');
        assert.match(row.away, /invalid source counts/);
        assert.doesNotMatch(row.away, /%/);
        assert.equal(row.better, null);
    });
}
test('count pairs preserve unknown, observed zero, legitimate rates and penalty counts without invented normalization', () => {
    const row = (a, b) =>
        nflBoxScoreRows(
            { third_down_conversions: a, third_down_attempts: b },
            {},
        ).find((r) => r.label === '3rd Down');
    assert.equal(row(null, 4).away, '—-4');
    assert.equal(row(0, 0).away, '0-0');
    assert.equal(row(0, 4).away, '0-4 (0%)');
    assert.equal(row(2, 4).away, '2-4 (50%)');
    assert.equal(
        nflBoxScoreRows({ penalties: 1, penalty_yards: 0 }, {}).find(
            (r) => r.label === 'Penalties (Pen-Yds)',
        ).away,
        '1-0',
    );
});

test('no unqualified provider or record clock is parsed by the freshness helper', () => {
    const parse = Date.parse;
    Date.parse = () => {
        throw new Error('unqualified clock parsed');
    };
    try {
        assert.match(
            liveSnapshotWarning(
                {
                    ...snapshot,
                    source_updated_at: '2026-11-27 06:00:00',
                    record_updated_at_raw: '2026-11-27 06:00:00',
                },
                now,
                false,
            ),
            /Provider observation freshness is unavailable/,
        );
    } finally {
        Date.parse = parse;
    }
});

test('invalid passing counts cannot win the composite row while observed yardage stays visible', () => {
    const row = nflBoxScoreRows(
        { passing_completions: 5, passing_attempts: 1, passing_yards: 500 },
        { passing_completions: 1, passing_attempts: 4, passing_yards: 100 },
    ).find((r) => r.label === 'Passing (C-A, Yds)');
    assert.equal(row.away, '5-1 (invalid source counts), 500');
    assert.equal(row.home, '1-4, 100');
    assert.equal(row.better, null);
    assert.equal(row.awayComplete, false);
    assert.equal(row.homeComplete, true);
});
