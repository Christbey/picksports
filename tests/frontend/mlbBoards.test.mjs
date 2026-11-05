import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { harness, queue, flush } from './fixtures/boardCleanupHarness.mjs';
let env;
before(async () => (env = await harness()));
after(() => env.server.close());
const prediction = (id) => ({
    id,
    game_id: id,
    game: {
        id,
        game_time: id === 1 ? '18:00:00' : '20:00:00',
        game_date: `2026-11-06T${id === 1 ? '18' : '20'}:00:00Z`,
        home_team: { display_name: 'Home', abbreviation: 'HOM' },
        away_team: { display_name: 'Away', abbreviation: 'AWY' },
    },
});
const candidate = (id, game_id = 1, extra = {}) => ({
    id,
    game_id,
    score: id,
    market_type: 'moneyline',
    is_tracking_only: true,
    game: { game_date: '2026-11-06T20:00:00Z' },
    ...extra,
});
const payload = (candidates = [], extra = {}) => ({
    data: {
        date: '2026-11-06',
        candidates,
        summary: {
            slate_games: 2,
            priced_games: 2,
            candidate_count: candidates.length,
        },
        market_counts: { all: candidates.length, moneyline: candidates.length },
        performance_summary: {},
        ...extra,
    },
});
async function mount(t) {
    const seasons = queue(),
        dates = queue(),
        predictions = queue(),
        picks = queue();
    const api = {
        predictions: {
            availableSeasons: seasons.run,
            availableDates: dates.run,
            index: predictions.run,
        },
        dailyPicks: { index: picks.run },
    };
    return {
        ...(await env.mount(t, 'useMlbMatchupBoard', api)),
        seasons,
        dates,
        predictions,
        picks,
    };
}
async function ready(x) {
    x.seasons.calls[0].resolve({ data: [2026, 2025] });
    await flush();
    x.dates.calls[0].resolve({ data: ['2026-11-06', '2026-11-07'] });
    await flush();
    x.predictions.calls[0].resolve({ data: [prediction(2), prediction(1)] });
    x.picks.calls[0].resolve(payload([candidate(1)]));
    await flush();
}
test('MLB initializes metadata and board exactly once with preserved filters, sort and period models', async (t) => {
    const x = await mount(t);
    x.seasons.calls[0].resolve({ data: [2026] });
    await flush();
    assert.equal(x.dates.calls.length, 1);
    x.dates.calls[0].resolve({ data: ['2026-11-06'] });
    await flush();
    assert.equal(x.predictions.calls.length, 1);
    assert.deepEqual(x.predictions.calls[0].args[1].query, {
        from_date: '2026-11-06',
        to_date: '2026-11-06',
        page: 1,
        per_page: 100,
        season: '2026',
    });
    assert.deepEqual(x.picks.calls[0].args[1].query, {
        date: '2026-11-06',
        compact: 1,
        season: '2026',
    });
    x.predictions.calls[0].resolve({ data: [prediction(2), prediction(1)] });
    x.picks.calls[0].resolve(
        payload([candidate(1)], {
            period_models_by_game: {
                1: [{ market_type: 'first_5_moneyline' }],
            },
        }),
    );
    await flush();
    assert.deepEqual(
        x.state.predictions.value.map((p) => p.id),
        [1, 2],
    );
    assert.equal(
        x.state.predictions.value[0].period_models[0].market_type,
        'first_5_moneyline',
    );
    x.state.searchQuery.value = 'home';
    assert.equal(x.state.filteredPredictions.value.length, 2);
    x.state.selectedFilter.value = 'candidates';
    assert.equal(x.state.filteredPredictions.value.length, 1);
});
test('MLB season changes cancel metadata and reject out-of-order dates without duplicate board requests', async (t) => {
    const x = await mount(t);
    x.seasons.calls[0].resolve({ data: [2026, 2025] });
    await flush();
    x.state.selectedSeason.value = '2025';
    assert.equal(x.dates.calls[0].args[1].init.signal.aborted, true);
    x.dates.calls[0].resolve({ data: ['2026-11-06'] });
    await flush();
    assert.equal(x.predictions.calls.length, 0);
    x.dates.calls[1].resolve({ data: ['2025-10-01'] });
    await flush();
    assert.equal(x.predictions.calls.length, 1);
    assert.equal(x.predictions.calls[0].args[1].query.season, '2025');
    x.predictions.calls[0].resolve({ data: [] });
    x.picks.calls[0].resolve(payload());
    await flush();
    assert.equal(x.state.selectedDate.value, '2025-10-01');
    assert.equal(x.state.loading.value, false);
});
test('MLB date changes abort old boards; stale failures cannot clear newer loading or populate the new date', async (t) => {
    const x = await mount(t);
    await ready(x);
    const old = x.state.refreshBoard();
    x.state.selectedDate.value =
        x.state.selectedDate.value === '2026-11-06'
            ? '2026-11-07'
            : '2026-11-06';
    assert.equal(x.predictions.calls[1].args[1].init.signal.aborted, true);
    assert.equal(x.state.predictions.value.length, 0);
    x.predictions.calls[1].reject(Error('Old failure'));
    x.picks.calls[1].resolve(payload());
    await old;
    assert.equal(x.state.refreshing.value, true);
    assert.equal(x.state.error.value, null);
    x.predictions.calls[2].resolve({ data: [prediction(2)] });
    x.picks.calls[2].resolve(payload());
    await flush();
    assert.equal(x.state.predictions.value[0].id, 2);
    assert.equal(x.state.refreshing.value, false);
});
test('MLB optional candidate failure preserves fresh predictions and same-query read failure retains prior rows', async (t) => {
    const x = await mount(t);
    await ready(x);
    let pending = x.state.refreshBoard();
    x.predictions.calls[1].resolve({ data: [prediction(2)] });
    x.picks.calls[1].reject(Error('Details offline'));
    await pending;
    assert.equal(x.state.predictions.value[0].id, 2);
    assert.match(x.state.error.value, /Candidate details/);
    pending = x.state.refreshBoard();
    x.predictions.calls[2].resolve(null);
    x.picks.calls[2].resolve(payload());
    await pending;
    assert.equal(x.state.boardStale.value, true);
    assert.equal(x.state.predictions.value[0].id, 2);
});
test('MLB failed metadata can retry, while successful empty dates do not loop metadata', async (t) => {
    const x = await mount(t);
    x.seasons.calls[0].resolve(null);
    await flush();
    assert.match(x.state.error.value, /seasons/);
    const retry = x.state.refreshBoard();
    x.seasons.calls[1].resolve({ data: [2026] });
    await flush();
    x.dates.calls[0].resolve({ data: [] });
    await retry;
    assert.equal(x.state.loading.value, false);
    await x.state.refreshBoard();
    assert.equal(x.dates.calls.length, 1);
    assert.equal(x.predictions.calls.length, 0);
});
test('MLB detail identity handles same-game reopen, closed drawers, and late errors', async (t) => {
    const x = await mount(t);
    await ready(x);
    let old = x.state.selectMatchup(prediction(1), candidate(1));
    x.state.detailOpen.value = false;
    const fresh = x.state.selectMatchup(prediction(1), candidate(2));
    assert.equal(x.picks.calls[1].args[1].init.signal.aborted, true);
    x.picks.calls[1].reject(Error('Old drawer'));
    await old;
    assert.equal(x.state.detailLoading.value, true);
    assert.equal(x.state.detailError.value, null);
    x.picks.calls[2].resolve(payload([candidate(2), candidate(3)]));
    await fresh;
    assert.equal(x.state.selectedCandidate.value.id, 2);
    assert.equal(x.state.detailLoading.value, false);
    old = x.state.selectMatchup(prediction(2), null);
    x.state.detailOpen.value = false;
    x.picks.calls[3].resolve(payload([candidate(9, 2)]));
    await old;
    assert.equal(x.state.detailOpen.value, false);
    assert.equal(x.state.selectedCandidate.value, null);
});
test('MLB aborts board and drawer reads on unmount and ignores late success', async (t) => {
    const x = await mount(t);
    await ready(x);
    const board = x.state.refreshBoard(),
        detail = x.state.selectMatchup(prediction(2), null);
    x.app.unmount();
    assert.equal(x.predictions.calls[1].args[1].init.signal.aborted, true);
    assert.equal(x.picks.calls[2].args[1].init.signal.aborted, true);
    x.predictions.calls[1].resolve({ data: [prediction(9)] });
    x.picks.calls[1].resolve(payload());
    x.picks.calls[2].resolve(payload([candidate(9)]));
    await Promise.all([board, detail]);
    assert.equal(x.state.predictions.value[0].id, 1);
    assert.equal(x.state.selectedCandidate.value, null);
});
async function daily(t) {
    const picks = queue();
    return {
        ...(await env.mount(
            t,
            'useMlbDailyPicksBoard',
            { dailyPicks: { index: picks.run } },
            { season: 2026 },
        )),
        picks,
    };
}
test('Daily MLB preserves top-per-game ranking and market filtering, including zero scores', async (t) => {
    const x = await daily(t);
    x.picks.calls[0].resolve(
        payload([
            candidate(0),
            candidate(8),
            candidate(7, 2, { market_type: 'first_5_total' }),
            candidate(6, 3, { market_type: 'first_3_moneyline' }),
        ]),
    );
    await flush();
    assert.deepEqual(
        x.state.visibleCandidates.value.map((c) => c.id),
        [8, 7, 6],
    );
    assert.equal(x.state.hiddenCandidateCount.value, 1);
    x.state.selectedTab.value = 'first_5';
    assert.deepEqual(
        x.state.visibleCandidates.value.map((c) => c.id),
        [7],
    );
    x.state.selectedTab.value = 'tracking';
    assert.equal(x.state.visibleCandidates.value.length, 3);
});
test('Daily MLB keeps explicit date refresh, protects season changes from stale responses, and retains same-query failures', async (t) => {
    const x = await daily(t);
    x.picks.calls[0].resolve(payload([candidate(1)]));
    await flush();
    x.state.selectedDate.value = '2026-11-07';
    await flush();
    assert.equal(x.picks.calls.length, 1);
    const old = x.state.loadPicks();
    assert.equal(x.state.payload.value, null);
    x.props.season = 2025;
    await flush();
    assert.equal(x.picks.calls[1].args[1].init.signal.aborted, true);
    assert.deepEqual(x.picks.calls[2].args[1].query, { season: 2025 });
    x.picks.calls[2].resolve(payload([candidate(5)], { date: '2025-10-01' }));
    await flush();
    x.picks.calls[1].resolve(payload([candidate(9)]));
    await old;
    assert.equal(x.state.payload.value.candidates[0].id, 5);
    const refresh = x.state.loadPicks();
    x.picks.calls[3].reject(Error('Read failed'));
    await refresh;
    assert.equal(x.state.payload.value.candidates[0].id, 5);
    assert.equal(x.state.error.value, 'Read failed');
});
test('Daily MLB null reads show recovery and unmount aborts without late writes', async (t) => {
    const x = await daily(t);
    x.picks.calls[0].resolve(null);
    await flush();
    assert.match(x.state.error.value, /Failed to load/);
    const pending = x.state.loadPicks();
    x.app.unmount();
    assert.equal(x.picks.calls[1].args[1].init.signal.aborted, true);
    x.picks.calls[1].resolve(payload([candidate(8)]));
    await pending;
    assert.equal(x.state.payload.value, null);
});
test('Daily MLB preserves tie ordering, input candidates and the twelve-game display limit', async (t) => {
    const x = await daily(t);
    const rows = Array.from({ length: 14 }, (_, i) =>
        candidate(i + 1, i + 1, {
            score: 70,
            game: { game_date: `2026-11-${String(i + 1).padStart(2, '0')}` },
        }),
    ).reverse();
    x.picks.calls[0].resolve(payload(rows));
    await flush();
    assert.deepEqual(
        x.state.visibleCandidates.value.map((row) => row.id),
        Array.from({ length: 12 }, (_, i) => i + 1),
    );
    assert.equal(x.state.hiddenCandidateCount.value, 2);
    assert.equal(x.state.payload.value.candidates[0].id, 14);
});
