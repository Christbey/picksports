import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    boardDeliveryMeta,
    createBoardDelivery,
    mergeBoardLivePredictions,
} from '../../resources/js/lib/predictionBoardDelivery.ts';

const base = () => ({
    id: 'forecast',
    sport: 'cfb',
    game_id: 1,
    projection: { predicted_spread: -2.58, home_win_probability: 0.605874 },
    value_signal: { decision_status: 'pass' },
    weather_forecast: { temperature_f: 44 },
    game: {
        id: 1,
        home_team: { abbreviation: 'KU' },
        status: 'STATUS_SCHEDULED',
        home_score: null,
    },
});

test('live patches preserve forecasts, evidence, team labels, nulls and zero values', () => {
    const current = [base()];
    const updated = mergeBoardLivePredictions(current, [
        {
            id: 'forecast',
            sport: 'cfb',
            game_id: 999,
            projection: { predicted_spread: 999 },
            value_signal: { decision_status: 'bet' },
            live_win_probability: 0,
            live_predicted_total: null,
            live_status: 'stale',
            game: {
                id: 1,
                home_team: { abbreviation: 'WRONG' },
                status: 'STATUS_IN_PROGRESS',
                home_score: 0,
                clock: null,
            },
        },
    ]);
    assert.deepEqual(updated[0].projection, current[0].projection);
    assert.deepEqual(updated[0].value_signal, current[0].value_signal);
    assert.deepEqual(updated[0].weather_forecast, current[0].weather_forecast);
    assert.equal(updated[0].game.home_team.abbreviation, 'KU');
    assert.equal(updated[0].game_id, 1);
    assert.equal(updated[0].game.home_score, 0);
    assert.equal(updated[0].game.clock, null);
    assert.equal(updated[0].live_win_probability, 0);
    assert.equal(updated[0].live_predicted_total, null);
    assert.equal(updated[0].live_status, 'stale');
    assert.equal(current[0].game.status, 'STATUS_SCHEDULED');
});

test('invalid membership or ordering never produces a partial board update', () => {
    for (const patches of [
        [],
        [{ ...base(), id: 'different' }],
        [{ ...base(), sport: 'nfl' }],
        [{ ...base(), game: { id: 2 } }],
    ]) {
        assert.throws(
            () => mergeBoardLivePredictions([base()], patches),
            /membership changed/,
        );
    }
    assert.throws(
        () =>
            mergeBoardLivePredictions(
                [base(), { ...base(), id: 'second' }],
                [{ ...base(), id: 'second' }, base()],
            ),
        /membership changed/,
    );
});

test('board delivery metadata accepts only supported modes and opaque versions', () => {
    assert.deepEqual(
        boardDeliveryMeta({ version: 'a'.repeat(64), mode: 'live' }),
        { version: 'a'.repeat(64), mode: 'live' },
    );
    for (const v of [
        null,
        {},
        { version: 'bad', mode: 'live' },
        { version: 'a'.repeat(64), mode: 'unknown' },
    ])
        assert.equal(boardDeliveryMeta(v), null);
});

const version = (letter) => letter.repeat(64);
const response = (rows, mode = 'replace', token = version('a')) => ({
    data: rows,
    meta: { pagination: { current_page: 1 }, board: { mode, version: token } },
});

test('unchanged context polls live while static changes replace the whole baseline', async () => {
    const queries = [];
    const replies = [
        response([base()]),
        response(
            [
                {
                    id: 'forecast',
                    sport: 'cfb',
                    game: {
                        id: 1,
                        status: 'STATUS_IN_PROGRESS',
                        home_score: 0,
                    },
                },
            ],
            'live',
        ),
        response(
            [{ ...base(), projection: { predicted_spread: -4 } }],
            'replace',
            version('b'),
        ),
        response(
            [
                {
                    id: 'forecast',
                    sport: 'cfb',
                    game: { id: 1, status: 'STATUS_FINAL' },
                },
            ],
            'live',
            version('b'),
        ),
    ];
    const load = createBoardDelivery(
        async (query) => {
            queries.push(query);
            return replies.shift();
        },
        () => true,
    );
    const q = { page: 1, week: 10 };
    await load(q);
    const live = await load(q, true);
    assert.equal(live.data[0].projection.predicted_spread, -2.58);
    assert.equal(live.data[0].game.home_score, 0);
    const replacement = await load(q, true);
    assert.equal(replacement.data[0].projection.predicted_spread, -4);
    const final = await load(q, true);
    assert.equal(final.data[0].projection.predicted_spread, -4);
    assert.equal(final.data[0].game.status, 'STATUS_FINAL');
    assert.equal(queries[0].view, 'board');
    assert.equal(queries[1].view, 'live');
    assert.equal(queries[1].board_version, version('a'));
    assert.equal(queries[3].board_version, version('b'));
});

test('late filter responses cannot overwrite the current page baseline', async () => {
    const pending = [],
        queries = [];
    const load = createBoardDelivery(
        (query) => {
            queries.push(query);
            return new Promise((resolve) => pending.push(resolve));
        },
        () => true,
    );
    const initial = load({ page: 1, week: 10 });
    pending.shift()(response([base()]));
    await initial;
    const old = load({ page: 1, week: 10 }, true);
    const filtered = load({ page: 1, week: 11 });
    pending[1](response([{ ...base(), id: 'new' }], 'replace', version('b')));
    await filtered;
    pending[0](
        response([{ id: 'forecast', sport: 'cfb', game: { id: 1 } }], 'live'),
    );
    await old;
    const next = load({ page: 1, week: 11 }, true);
    assert.equal(queries.at(-1).board_version, version('b'));
    pending[2](
        response(
            [
                {
                    id: 'new',
                    sport: 'cfb',
                    game: { id: 1, status: 'STATUS_FINAL' },
                },
            ],
            'live',
            version('b'),
        ),
    );
    assert.equal((await next).data[0].id, 'new');
});

test('invalid live updates are rejected and the next retry requests a replacement', async () => {
    const queries = [],
        replies = [
            response([base()]),
            response([], 'live'),
            response([base()]),
        ];
    const load = createBoardDelivery(
        async (query) => {
            queries.push(query);
            return replies.shift();
        },
        () => true,
    );
    await load({ page: 1 });
    await assert.rejects(load({ page: 1 }, true), /membership changed/);
    await load({ page: 1 }, true);
    assert.equal(queries[2].view, 'board');
    assert.equal(queries[2].board_version, undefined);
});

test('other sports and older servers retain their full-response behavior', async () => {
    const queries = [];
    const full = { data: [base()], meta: { pagination: { current_page: 1 } } };
    const load = createBoardDelivery(
        async (query) => {
            queries.push(query);
            return full;
        },
        () => false,
    );
    assert.deepEqual(await load({ page: 2 }, true), full);
    assert.deepEqual(queries[0], { page: 2 });
    const compatibility = createBoardDelivery(
        async () => full,
        () => true,
    );
    assert.deepEqual(await compatibility({ page: 1 }), full);
    assert.deepEqual(await compatibility({ page: 1 }, true), full);
});
