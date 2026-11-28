import assert from 'node:assert/strict';
import { test } from 'node:test';
import { liveSnapshotWarning } from '../../resources/js/lib/nflLiveSnapshot.ts';

const now = Date.parse('2026-09-18T01:43:33Z');
const snapshot = {
    game: { status: 'STATUS_IN_PROGRESS' },
    projection: {},
    source_updated_at: null,
    record_updated_at: '2026-09-18T01:43:06Z',
    source_clock_status: 'observation_unavailable',
};

test('live freshness distinguishes unavailable provider observation from stored record age', () => {
    assert.match(
        liveSnapshotWarning(snapshot, now, false),
        /Provider observation freshness is unavailable/,
    );
    assert.match(liveSnapshotWarning(snapshot, now, true), /Refresh failed/);
    assert.match(liveSnapshotWarning(null, now, false), /Waiting/);
    assert.match(
        liveSnapshotWarning(snapshot, now + 180000, false),
        /Provider observation freshness is unavailable/,
    );
    for (const timestamp of [null, 'bad', '2026-09-19T00:00:00Z']) {
        assert.match(
            liveSnapshotWarning(
                { ...snapshot, record_updated_at: timestamp },
                now,
                false,
            ),
            /Provider observation freshness is unavailable/,
        );
    }
});

test('incomplete live state is unavailable rather than a fabricated projection', () => {
    assert.match(
        liveSnapshotWarning({ ...snapshot, projection: null }, now, false),
        /Projection unavailable/,
    );
    assert.match(
        liveSnapshotWarning(
            { ...snapshot, projection: null, game: { status: 'STATUS_FINAL' } },
            now,
            false,
        ),
        /Provider observation freshness is unavailable/,
    );
});
