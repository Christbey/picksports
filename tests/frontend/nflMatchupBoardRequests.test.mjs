import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { harness, queue, flush } from './fixtures/boardCleanupHarness.mjs';
let env, presentation;
before(async () => {
    env = await harness();
    presentation = await env.server.ssrLoadModule(
        '/resources/js/lib/nflMatchupBoardPresentation.ts',
    );
});
after(() => env.server.close());
const prediction = (id) => ({
    id,
    game_id: id,
    game: {
        game_date: '2026-11-06',
        game_time: id === 1 ? '12:00:00' : '16:00:00',
        short_name: 'AWY @ HOM',
        home_team: { abbreviation: 'HOM' },
        away_team: { abbreviation: 'AWY' },
    },
});
async function mount(t) {
    const seasons = queue(),
        dates = queue(),
        boards = queue();
    return {
        ...(await env.mount(t, 'useNflMatchupBoard', {
            predictions: {
                availableSeasons: seasons.run,
                availableDates: dates.run,
                index: boards.run,
            },
        })),
        seasons,
        dates,
        boards,
    };
}
async function ready(x) {
    x.seasons.calls[0].resolve({ data: [2026, 2025] });
    await flush();
    x.dates.calls[0].resolve({ data: ['2026-11-06', '2026-11-07'] });
    await flush();
    x.boards.calls[0].resolve({ data: [prediction(2), prediction(1)] });
    await flush();
}
test('NFL board initializes once and preserves query contracts, time sorting and local filters', async (t) => {
    const x = await mount(t);
    await ready(x);
    assert.equal(x.dates.calls.length, 1);
    assert.equal(x.boards.calls.length, 1);
    const date = x.state.selectedDate.value;
    assert.deepEqual(x.boards.calls[0].args, [
        'nfl',
        {
            query: {
                from_date: date,
                to_date: date,
                page: 1,
                per_page: 100,
                season: '2026',
            },
            init: { signal: x.boards.calls[0].args[1].init.signal },
        },
    ]);
    assert.deepEqual(
        x.state.predictions.value.map((p) => p.id),
        [1, 2],
    );
    x.state.searchQuery.value = ' HOM ';
    assert.equal(x.state.filteredPredictions.value.length, 2);
    x.state.searchQuery.value = 'missing';
    assert.equal(x.state.filteredPredictions.value.length, 0);
    assert.equal(x.boards.calls.length, 1);
});
test('NFL metadata failures retry metadata rather than silently becoming empty boards', async (t) => {
    const x = await mount(t);
    x.seasons.calls[0].resolve(null);
    await flush();
    assert.match(x.state.error.value, /seasons/);
    assert.equal(x.boards.calls.length, 0);
    const retry = x.state.refreshBoard();
    x.seasons.calls[1].resolve({ data: [2026] });
    await flush();
    x.dates.calls[0].reject(Error('Dates failed'));
    await retry;
    assert.equal(x.state.error.value, 'Dates failed');
    const again = x.state.refreshBoard();
    x.seasons.calls[2].resolve({ data: [2026] });
    await flush();
    x.dates.calls[1].resolve({ data: [] });
    await again;
    assert.equal(x.state.error.value, null);
    assert.equal(x.state.loading.value, false);
    await x.state.refreshBoard();
    assert.equal(x.dates.calls.length, 2);
    assert.equal(x.boards.calls.length, 0);
});
test('NFL rapid A-B-A season changes reject late metadata even when the old season matches again', async (t) => {
    const x = await mount(t);
    x.seasons.calls[0].resolve({ data: [2026, 2025] });
    await flush();
    x.state.selectedSeason.value = '2025';
    x.state.selectedSeason.value = '2026';
    assert.equal(x.dates.calls.length, 3);
    assert.equal(x.dates.calls[0].args[1].init.signal.aborted, true);
    x.dates.calls[0].resolve({ data: ['2026-01-01'] });
    x.dates.calls[1].reject(Error('Old season'));
    await flush();
    assert.equal(x.boards.calls.length, 0);
    assert.equal(x.state.filtersLoading.value, true);
    assert.equal(x.state.error.value, null);
    x.dates.calls[2].resolve({ data: ['2026-11-06'] });
    await flush();
    assert.equal(x.boards.calls.length, 1);
    assert.equal(x.state.selectedDate.value, '2026-11-06');
    x.boards.calls[0].resolve({ data: [] });
});
test('NFL superseded board failures cannot clear newer loading or replace its results', async (t) => {
    const x = await mount(t);
    await ready(x);
    const old = x.state.refreshBoard();
    x.state.selectedDate.value =
        x.state.selectedDate.value === '2026-11-06'
            ? '2026-11-07'
            : '2026-11-06';
    assert.equal(x.boards.calls[1].args[1].init.signal.aborted, true);
    assert.equal(x.state.predictions.value.length, 2);
    x.boards.calls[1].reject(Error('Old error'));
    await old;
    assert.equal(x.state.refreshing.value, true);
    assert.equal(x.state.error.value, null);
    x.boards.calls[2].resolve({ data: [prediction(3)] });
    await flush();
    assert.equal(x.state.predictions.value[0].id, 3);
    assert.equal(x.state.refreshing.value, false);
});
test('NFL failed refresh retains same-date cards but never presents another date as current after failure', async (t) => {
    const x = await mount(t);
    await ready(x);
    const refresh = x.state.refreshBoard();
    x.boards.calls[1].resolve(null);
    await refresh;
    assert.equal(x.state.boardStale.value, true);
    assert.equal(x.state.predictions.value.length, 2);
    x.state.selectedDate.value =
        x.state.selectedDate.value === '2026-11-06'
            ? '2026-11-07'
            : '2026-11-06';
    x.boards.calls[2].reject(Error('New date unavailable'));
    await flush();
    assert.equal(x.state.boardStale.value, false);
    assert.equal(x.state.predictions.value.length, 0);
    assert.equal(x.state.error.value, 'New date unavailable');
});
test('NFL same-query refresh updates the open drawer and closes it when the prediction disappears', async (t) => {
    const x = await mount(t);
    await ready(x);
    x.state.selectMatchup(x.state.predictions.value[0]);
    let refresh = x.state.loadBoard();
    x.boards.calls[1].resolve({
        data: [{ ...prediction(1), revision: 'new' }],
    });
    await refresh;
    assert.equal(x.state.detailOpen.value, true);
    assert.equal(x.state.selectedPrediction.value.revision, 'new');
    refresh = x.state.loadBoard();
    x.boards.calls[2].resolve({ data: [] });
    await refresh;
    assert.equal(x.state.detailOpen.value, false);
    assert.equal(x.state.selectedPrediction.value, null);
});
test('NFL season changes close the old drawer and metadata loading cannot start a wrong-date board read', async (t) => {
    const x = await mount(t);
    await ready(x);
    x.state.selectMatchup(prediction(1));
    x.state.selectedSeason.value = '2025';
    assert.equal(x.state.detailOpen.value, false);
    await x.state.loadBoard();
    assert.equal(x.boards.calls.length, 1);
    x.dates.calls[1].resolve({ data: ['2025-10-01'] });
    await flush();
    assert.deepEqual(x.boards.calls[1].args[1].query, {
        from_date: '2025-10-01',
        to_date: '2025-10-01',
        page: 1,
        per_page: 100,
        season: '2025',
    });
    x.boards.calls[1].resolve({ data: [] });
});
test('NFL metadata aborts on unmount and cannot start follow-up requests', async (t) => {
    const x = await mount(t);
    x.app.unmount();
    assert.equal(x.seasons.calls[0].args[1].init.signal.aborted, true);
    x.seasons.calls[0].resolve({ data: [2026] });
    await flush();
    assert.equal(x.dates.calls.length, 0);
    await x.state.refreshBoard();
    assert.equal(x.seasons.calls.length, 1);
});
test('NFL board aborts on unmount and ignores late results and errors', async (t) => {
    const x = await mount(t);
    await ready(x);
    const pending = x.state.loadBoard();
    x.app.unmount();
    assert.equal(x.boards.calls[1].args[1].init.signal.aborted, true);
    x.boards.calls[1].resolve({ data: [prediction(9)] });
    await pending;
    assert.equal(x.state.predictions.value[0].id, 1);
    x.state.selectMatchup(prediction(9));
    assert.equal(x.state.detailOpen.value, false);
});
test('NFL filter semantics preserve reviewed candidates, thresholds, finals and search evidence', () => {
    const { filterMatches, searchHaystack } = presentation;
    const p = {
        ...prediction(1),
        predicted_spread: 5,
        win_probability: 0.6,
        nfl_board: {
            market: { home_spread: -2.5 },
            research: { status: 'reviewed', decision: 'candidate' },
        },
        pro_signal_layer: {
            bet_classification: 'bet',
            market_scores: { winner: { tier: 'watchlist' } },
            reason_codes: ['stored_reason'],
        },
    };
    assert.equal(filterMatches(p, 'bets'), true);
    assert.equal(
        filterMatches(
            {
                ...p,
                nfl_board: {
                    ...p.nfl_board,
                    research: { status: 'reviewed', decision: 'pass' },
                },
            },
            'bets',
        ),
        false,
    );
    assert.equal(filterMatches(p, 'spread'), true);
    assert.equal(filterMatches(p, 'winner'), true);
    assert.equal(
        filterMatches({ ...p, status: 'STATUS_FINAL' }, 'finals'),
        true,
    );
    assert.match(searchHaystack(p), /stored_reason/);
});
