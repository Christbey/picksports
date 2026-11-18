import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fetchPredictionSlate } from '../../resources/js/lib/fetchPredictionSlate.ts';

const payload = (id, lastPage = 3) => ({
    data: [{ game_id: id }],
    meta: { pagination: { last_page: lastPage } },
});

test('complete slates include every page in API order', async () => {
    const requests = [];
    const rows = await fetchPredictionSlate(
        async (page) => {
            requests.push(page);
            return payload(page);
        },
        () => true,
    );
    assert.deepEqual(requests, [1, 2, 3]);
    assert.deepEqual(
        rows.map((row) => row.game_id),
        [1, 2, 3],
    );
});

test('an empty slate is successful and does not request more pages', async () => {
    let calls = 0;
    assert.deepEqual(
        await fetchPredictionSlate(
            async () => {
                calls++;
                return { data: [], meta: { pagination: { last_page: 1 } } };
            },
            () => true,
        ),
        [],
    );
    assert.equal(calls, 1);
});

test('a superseded slate stops pagination without publishing partial rows', async () => {
    let active = true;
    const requests = [];
    const rows = await fetchPredictionSlate(
        async (page) => {
            requests.push(page);
            if (page === 2) active = false;
            return payload(page);
        },
        () => active,
    );
    assert.equal(rows, null);
    assert.deepEqual(requests, [1, 2]);
});

test('a failed later page cannot be mistaken for a complete slate', async () => {
    const requests = [];
    const rows = await fetchPredictionSlate(
        async (page) => {
            requests.push(page);
            return page === 2 ? null : payload(page);
        },
        () => true,
    );
    assert.equal(rows, null);
    assert.deepEqual(requests, [1, 2]);
    await assert.rejects(
        fetchPredictionSlate(
            async (page) => {
                if (page === 2) throw Error('Page two unavailable');
                return payload(page);
            },
            () => true,
        ),
        /Page two unavailable/,
    );
});
