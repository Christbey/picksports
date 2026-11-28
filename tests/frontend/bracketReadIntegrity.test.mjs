import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp, provide, ref } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { createRenderer, h, nextTick, reactive } from 'vue';
import { fileURLToPath } from 'node:url';
import { queue, flush } from './fixtures/boardCleanupHarness.mjs';
let server;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'bracket-boundary-fakes',
                enforce: 'pre',
                transform(source, id) {
                    if (id.endsWith('/useMarchMadnessBracket.ts'))
                        return source.replace(
                            "import { usePage } from '@inertiajs/vue3';",
                            'const usePage=()=>globalThis.__bracketPage;',
                        );
                },
                load(id) {
                    if (id === '\0bracket-page')
                        return 'export const usePage=()=>globalThis.__bracketPage;';
                    if (id.endsWith('/useApiV2Client.ts'))
                        return 'export const useApiV2Client=()=>globalThis.__bracketApi;';
                    if (id.endsWith('/useAppearance.ts'))
                        return "import {ref} from 'vue'; export const useAppearance=()=>({resolvedAppearance:ref('light'),updateAppearance(){}});";
                },
            },
            vue(),
        ],
        resolve: {
            alias: {
                '@': fileURLToPath(
                    new URL('../../resources/js', import.meta.url),
                ),
            },
        },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
});
after(() => server.close());
const renderer = createRenderer({
    createElement: () => ({}),
    createText: () => ({}),
    createComment: () => ({}),
    insert() {},
    remove() {},
    setText() {},
    setElementText() {},
    parentNode: () => null,
    nextSibling: () => null,
    patchProp() {},
});
const bracket = (id, groupId = null) => ({
    public_id: id,
    name: id,
    group_id: groupId,
    picks: {},
    lock_at: '2099-01-01T00:00:00Z',
    can_edit: true,
    is_locked: false,
});
async function mount(t) {
    const priorWindow = globalThis.window;
    const store = new Map();
    let timerId = 0;
    const timers = new Map();
    globalThis.window = {
        localStorage: {
            getItem: (k) => store.get(k) ?? null,
            setItem: (k, v) => store.set(k, v),
            removeItem: (k) => store.delete(k),
        },
        setTimeout: (fn) => {
            const id = ++timerId;
            timers.set(id, fn);
            return id;
        },
        clearTimeout: (id) => timers.delete(id),
    };
    globalThis.__bracketPage = reactive({
        props: { auth: { user: { id: 1 } } },
    });
    const index = queue(),
        groups = queue(),
        leaderboard = queue(),
        update = queue(),
        create = queue();
    globalThis.__bracketApi = {
        cbbBrackets: {
            index: index.run,
            leaderboard: leaderboard.run,
            update: update.run,
            store: create.run,
        },
        groups: { index: groups.run },
    };
    const hooks = await server.ssrLoadModule(
        '/resources/js/composables/useMarchMadnessBracket.ts',
    );
    let state;
    const app = renderer.createApp({
        setup() {
            state = hooks.useMarchMadnessBracket({ regions: [] });
            return () => h('div');
        },
    });
    app.mount({});
    t.after(() => {
        app.unmount();
        globalThis.window = priorWindow;
    });
    await flush();
    return {
        state,
        storage: store,
        index,
        groups,
        leaderboard,
        update,
        create,
        page: globalThis.__bracketPage,
        runTimers: async () => {
            const queued = [...timers.values()];
            timers.clear();
            await Promise.all(queued.map((fn) => fn()));
        },
    };
}
test('bracket identity replacement clears account data and refuses delayed old-account responses', async (t) => {
    const x = await mount(t);
    x.groups.calls[0].resolve({
        data: [{ id: 7, owner_id: 1, name: 'Old private pool' }],
    });
    x.leaderboard.calls[0].resolve({
        data: [{ bracket_name: 'Old private standings' }],
    });
    await flush();
    x.page.props.auth.user = { id: 2 };
    await nextTick();
    assert.equal(x.index.calls[0].args[0].init.signal.aborted, true);
    assert.deepEqual(x.state.availableGroups, []);
    assert.deepEqual(x.state.leaderboard, []);
    assert.equal(x.state.currentBracket, null);
    x.index.calls[0].resolve({ data: [bracket('old-account-private')] });
    await flush();
    assert.equal(x.state.currentBracket, null);
    assert.deepEqual(x.state.brackets, []);
});
test('late metadata save updates only its source bracket and cannot switch the active bracket back', async (t) => {
    const x = await mount(t);
    x.groups.calls[0].resolve({ data: [] });
    x.leaderboard.calls[0].resolve({ data: [] });
    x.index.calls[0].resolve({ data: [bracket('first'), bracket('second')] });
    await flush();
    const saving = x.state.saveBracketMeta();
    x.state.switchBracket('second');
    x.update.calls[0].resolve({
        data: { ...bracket('first'), name: 'Saved first' },
    });
    await saving;
    assert.equal(x.state.activeBracketPublicId, 'second');
    assert.equal(x.state.currentBracket.public_id, 'second');
    assert.equal(
        x.state.brackets.find((b) => b.public_id === 'first').name,
        'Saved first',
    );
});
test('out-of-order leaderboard responses remain bound to the currently selected group', async (t) => {
    const x = await mount(t);
    x.groups.calls[0].resolve({ data: [] });
    x.leaderboard.calls[0].resolve({ data: [] });
    x.index.calls[0].resolve({
        data: [bracket('first', 1), bracket('second', 2)],
    });
    await flush();
    x.state.switchBracket('second');
    await flush();
    const recent = x.leaderboard.calls.at(-1),
        old = x.leaderboard.calls.at(-2);
    recent.resolve({ data: [{ bracket_name: 'Second group' }] });
    await flush();
    old.resolve({ data: [{ bracket_name: 'First group' }] });
    await flush();
    assert.equal(x.state.leaderboard[0].bracket_name, 'Second group');
});

test('autosave keeps its captured bracket when the active selection changes during HTTP', async (t) => {
    const x = await mount(t);
    x.groups.calls[0].resolve({ data: [] });
    x.leaderboard.calls[0].resolve({ data: [] });
    x.index.calls[0].resolve({ data: [bracket('first'), bracket('second')] });
    await flush();
    x.state.picks = { 'East-round_of_64-0': 'seed:1' };
    await flush();
    const saving = x.runTimers();
    await flush();
    assert.equal(x.update.calls[0].args[0], 'first');
    x.state.switchBracket('second');
    await flush();
    x.update.calls[0].resolve({
        data: {
            ...bracket('first'),
            picks: { 'East-round_of_64-0': 'seed:1' },
        },
    });
    await saving;
    assert.equal(x.state.activeBracketPublicId, 'second');
    assert.deepEqual(x.state.picks, {});
});
test('same-account picks returning to the loaded value still correct an in-flight different save', async (t) => {
    const x = await mount(t);
    x.groups.calls[0].resolve({ data: [] });
    x.leaderboard.calls[0].resolve({ data: [] });
    x.index.calls[0].resolve({ data: [bracket('first')] });
    await flush();
    x.state.picks = { 'East-round_of_64-0': 'seed:1' };
    await flush();
    const first = x.runTimers();
    await flush();
    x.state.picks = {};
    await flush();
    const second = x.runTimers();
    await flush();
    assert.equal(x.update.calls.length, 1);
    x.update.calls[0].resolve({
        data: {
            ...bracket('first'),
            picks: { 'East-round_of_64-0': 'seed:1' },
        },
    });
    await first;
    await flush();
    assert.equal(x.update.calls.length, 2);
    assert.deepEqual(x.update.calls[1].args[1].picks, {});
    x.update.calls[1].resolve({ data: bracket('first') });
    await second;
    assert.deepEqual(x.state.picks, {});
    assert.deepEqual(x.state.currentBracket.picks, {});
});

test('actual participant component hides unknown probabilities while preserving a genuine zero', async () => {
    const { useBracketTournament } = await server.ssrLoadModule(
        '/resources/js/composables/useBracketTournament.ts',
    );
    const { bracketBuilderKey } = await server.ssrLoadModule(
        '/resources/js/composables/useBracketBuilderContext.ts',
    );
    const Component = (
        await server.ssrLoadModule(
            '/resources/js/components/bracket/BracketParticipantButton.vue',
        )
    ).default;
    const slot = {
        participant: {
            id: 'team:1',
            name: 'Known participant',
            abbreviation: 'K',
        },
    };
    for (const probability of [null, 0, 0.625, NaN, 2]) {
        const game = {
            homeTeam: { id: 1 },
            awayTeam: { id: 2 },
            prediction: {
                homeWinProbability: probability,
                awayWinProbability: null,
            },
        };
        const matchup = {
            id: 'known',
            roundKey: 'round_of_64',
            participants: [slot, { participant: null }],
            game,
        };
        const state = useBracketTournament(
            { regions: [] },
            ref({}),
            ref(null),
            () => false,
        );
        const html = await renderToString(
            createSSRApp({
                setup() {
                    provide(
                        bracketBuilderKey,
                        reactive({ ...state, isBracketLocked: false }),
                    );
                    return () =>
                        h(Component, {
                            matchup,
                            participantSlot: slot,
                            variant: 'regional-mobile',
                        });
                },
            }),
        );
        if (probability === 0) assert.match(html, /0% ML/);
        else if (probability === 0.625) assert.match(html, /63% ML/);
        else assert.doesNotMatch(html, /% ML/);
    }
});

test('a failed account bracket read cannot automatically create a new bracket from a local draft', async (t) => {
    const RealDate = globalThis.Date;
    globalThis.Date = class extends RealDate {
        constructor(...args) {
            super(...(args.length ? args : ['2026-03-01T12:00:00Z']));
        }
        static now() {
            return new RealDate('2026-03-01T12:00:00Z').getTime();
        }
    };
    t.after(() => {
        globalThis.Date = RealDate;
    });
    const x = await mount(t);
    x.groups.calls[0].resolve({ data: [] });
    x.leaderboard.calls[0].resolve({ data: [] });
    x.state.picks = { 'East-round_of_64-0': 'seed:1' };
    await flush();
    x.index.calls[0].resolve(null);
    await flush();
    assert.equal(x.create.calls.length, 0);
    assert.match(x.state.accountReadErrors.brackets, /could not be loaded/i);
    assert.deepEqual(x.state.picks, { 'East-round_of_64-0': 'seed:1' });
    const { bracketBuilderKey } = await server.ssrLoadModule(
        '/resources/js/composables/useBracketBuilderContext.ts',
    );
    const Overview = (
        await server.ssrLoadModule(
            '/resources/js/components/bracket/BracketOverview.vue',
        )
    ).default;
    const html = await renderToString(
        createSSRApp({
            setup() {
                provide(bracketBuilderKey, x.state);
                return () => h(Overview);
            },
        }),
    );
    assert.match(html, /role="alert"/);
    assert.match(html, /Retry account reads/);
    const retry = x.state.reloadAccountData();
    x.groups.calls[1].resolve({ data: [] });
    x.leaderboard.calls.at(-1).resolve({ data: [] });
    x.index.calls[1].resolve({ data: [] });
    await flush();
    assert.equal(x.create.calls.length, 1);
    x.create.calls[0].resolve({
        data: {
            ...bracket('recovered'),
            picks: { 'East-round_of_64-0': 'seed:1' },
        },
    });
    await retry;
    assert.equal(x.state.currentBracket.public_id, 'recovered');
    assert.equal(x.state.accountReadErrors.brackets, null);
});
