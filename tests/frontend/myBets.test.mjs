import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { harness, queue, flush } from './fixtures/boardCleanupHarness.mjs';
let env, presentation;
before(async () => {
    env = await harness();
    presentation = await env.server.ssrLoadModule(
        '/resources/js/lib/myBetsPresentation.ts',
    );
});
after(() => env.server.close());
const response = (id = 1) => ({
    bets: {
        data: [{ id, result: 'pending', profit_loss: 0 }],
        links: [],
        current_page: 1,
        last_page: 1,
    },
    statistics: {
        total_bets: 1,
        total_wagered: 10,
        wins: 0,
        losses: 0,
        pushes: 0,
        win_rate: 0,
        total_profit: 0,
        roi: 0,
    },
});
async function mount(t) {
    const reads = queue(),
        writes = queue(),
        updates = queue(),
        deletes = queue();
    const api = {
        userBets: {
            index: reads.run,
            store: writes.run,
            update: updates.run,
            destroy: deletes.run,
            exportUrl: () => '/api/v2/user-bets/export',
        },
    };
    return {
        ...(await env.mount(t, 'useMyBets', api)),
        reads,
        writes,
        updates,
        deletes,
    };
}
test('My Bets rejects stale reads and retains server statistics after refresh failure', async (t) => {
    const { state, reads } = await mount(t);
    const latest = state.fetchBets();
    assert.equal(reads.calls[0].args[0].init.signal.aborted, true);
    reads.calls[1].resolve(response(2));
    await latest;
    await flush();
    reads.calls[0].resolve(response(1));
    await flush();
    assert.equal(state.bets.value.data[0].id, 2);
    const refresh = state.fetchBets();
    reads.calls[2].reject(Error('offline'));
    await refresh;
    assert.equal(state.error.value, 'offline');
    assert.equal(state.hasLoaded.value, true);
    assert.equal(state.statistics.value.total_profit, 0);
    assert.equal(state.bets.value.data[0].id, 2);
});
test('My Bets distinguishes unavailable reads from an empty history and aborts on unmount', async (t) => {
    const { state, reads, app } = await mount(t);
    reads.calls[0].resolve(null);
    await flush();
    assert.match(state.error.value, /Unable to load/);
    assert.equal(state.hasLoaded.value, false);
    const pending = state.fetchBets();
    app.unmount();
    assert.equal(reads.calls[1].args[0].init.signal.aborted, true);
    reads.calls[1].resolve(response());
    await pending;
    assert.equal(state.hasLoaded.value, false);
});
test('My Bets submits the same manual payload once and keeps invalid form values', async (t) => {
    const { state, reads, writes } = await mount(t);
    reads.calls[0].resolve(response());
    await flush();
    state.showAddBetForm.value = true;
    Object.assign(state.form.value, {
        bet_amount: '25.50',
        odds: '-110',
        line: '0',
        notes: 'Keep this',
        bet_type: 'total_under',
    });
    await flush();
    assert.equal(state.form.value.selection_side, 'under');
    const pending = state.submitBet();
    await state.submitBet();
    assert.equal(writes.calls.length, 1);
    assert.deepEqual(writes.calls[0].args[0], {
        ...state.form.value,
        prediction_id: null,
        prediction_sport: null,
    });
    writes.calls[0].reject(
        Object.assign(Error('Invalid'), {
            data: { errors: { odds: ['Enter valid odds.'] } },
        }),
    );
    await pending;
    assert.equal(state.formError.value, 'Enter valid odds.');
    assert.equal(state.form.value.odds, '-110');
    assert.equal(state.form.value.notes, 'Keep this');
    assert.equal(state.showAddBetForm.value, true);
    assert.equal(state.submitting.value, false);
});
test('My Bets preserves linked payloads and reports a post-save read failure as recovery', async (t) => {
    const { state, reads, writes } = await mount(t);
    reads.calls[0].resolve(response());
    await flush();
    Object.assign(state.form.value, {
        prediction_id: '42',
        prediction_sport: 'mlb',
        bet_amount: '10',
        odds: '+120',
        bet_type: 'moneyline',
        selection_side: 'away',
    });
    const pending = state.submitBet();
    assert.equal(writes.calls[0].args[0].prediction_id, '42');
    assert.equal(writes.calls[0].args[0].prediction_sport, 'mlb');
    writes.calls[0].resolve(null);
    await flush();
    reads.calls[1].reject(Error('Refresh unavailable'));
    await pending;
    assert.equal(state.formError.value, null);
    assert.equal(state.error.value, 'Refresh unavailable');
    assert.equal(state.form.value.prediction_id, '');
    assert.equal(state.form.value.selection_side, 'home');
});
test('My Bets serializes actions on one row, keeps result payloads, and leaves rows unchanged on failure', async (t) => {
    const { state, reads, updates, deletes } = await mount(t);
    reads.calls[0].resolve(response());
    await flush();
    const oldConfirm = globalThis.confirm;
    globalThis.confirm = () => true;
    t.after(() => (globalThis.confirm = oldConfirm));
    const pending = state.updateBetResult(1, 'won');
    await state.updateBetResult(1, 'lost');
    await state.deleteBet(1);
    assert.equal(updates.calls.length, 1);
    assert.equal(deletes.calls.length, 0);
    assert.deepEqual(updates.calls[0].args, [1, { result: 'won' }]);
    assert.equal(state.isBetPending(1), true);
    updates.calls[0].reject(Error('Unable to settle'));
    await pending;
    assert.equal(state.isBetPending(1), false);
    assert.equal(state.bets.value.data[0].result, 'pending');
    assert.equal(state.mutationError.value, 'Unable to settle');
    globalThis.confirm = () => false;
    await state.deleteBet(1);
    assert.equal(deletes.calls.length, 0);
    globalThis.confirm = () => true;
    const deletion = state.deleteBet(1);
    deletes.calls[0].resolve(null);
    await flush();
    reads.calls[1].resolve({
        ...response(),
        bets: { ...response().bets, data: [] },
    });
    await deletion;
    await flush();
    assert.equal(state.bets.value.data.length, 0);
});
test('My Bets never refreshes or resets forms after a late write completes on an unmounted page', async (t) => {
    const { state, reads, writes, app } = await mount(t);
    state.form.value.notes = 'Preserve';
    const pending = state.submitBet();
    app.unmount();
    writes.calls[0].resolve(null);
    await pending;
    assert.equal(reads.calls.length, 1);
    assert.equal(state.form.value.notes, 'Preserve');
});
test('My Bets keeps existing selection and currency presentation including zero and negative values', () => {
    const { displaySelection, formatCurrency } = presentation;
    const bet = {
        selection_label: null,
        bet_type: 'spread',
        selection_side: 'away',
        line: 0,
    };
    assert.equal(displaySelection(bet), 'AWAY 0');
    assert.equal(displaySelection({ ...bet, line: 1.5 }), 'AWAY +1.5');
    assert.equal(displaySelection({ ...bet, line: -1.5 }), 'AWAY -1.5');
    assert.equal(
        displaySelection({ ...bet, bet_type: 'total_over' }),
        'Over 0',
    );
    assert.equal(
        displaySelection({ ...bet, bet_type: 'moneyline' }),
        'AWAY ML',
    );
    assert.equal(
        displaySelection({ ...bet, selection_label: 'Stored label' }),
        'Stored label',
    );
    assert.equal(formatCurrency(0), '$0.00');
    assert.equal(formatCurrency(-25.5), '-$25.50');
});
test('My Bets keeps export routing and total-side transitions unchanged', async (t) => {
    const { state } = await mount(t);
    const original = globalThis.window;
    globalThis.window = { location: { href: '' } };
    t.after(() => (globalThis.window = original));
    state.exportBets();
    assert.equal(globalThis.window.location.href, '/api/v2/user-bets/export');
    state.form.value.bet_type = 'total_over';
    await flush();
    assert.equal(state.form.value.selection_side, 'over');
    state.form.value.bet_type = 'spread';
    await flush();
    assert.equal(state.form.value.selection_side, 'home');
    state.form.value.selection_side = 'away';
    state.form.value.bet_type = 'moneyline';
    await flush();
    assert.equal(state.form.value.selection_side, 'away');
});
test('My Bets prevents a duplicate delete while preserving server rows and amounts on failure', async (t) => {
    const { state, reads, deletes } = await mount(t);
    reads.calls[0].resolve(response());
    await flush();
    const original = globalThis.confirm;
    let confirms = 0;
    globalThis.confirm = () => {
        confirms++;
        return true;
    };
    t.after(() => (globalThis.confirm = original));
    const first = state.deleteBet(1);
    await state.deleteBet(1);
    assert.equal(confirms, 1);
    assert.equal(deletes.calls.length, 1);
    deletes.calls[0].reject(Error('Delete unavailable'));
    await first;
    assert.equal(state.bets.value.data[0].id, 1);
    assert.equal(state.statistics.value.total_wagered, 10);
    assert.equal(state.mutationError.value, 'Delete unavailable');
});
