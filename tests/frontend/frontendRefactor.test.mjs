import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import {
    createRenderer,
    createSSRApp,
    effectScope,
    h,
    nextTick,
    reactive,
    ref,
    ssrContextKey,
} from 'vue';
import { renderToString } from 'vue/server-renderer';
import { useAsyncPolling } from '../../resources/js/composables/useAsyncPolling.ts';

let server;
before(async () => {
    globalThis.__refactorPage = { props: { auth: { user: null } } };
    server = await createServer({
        configFile: false,
        ssr: { noExternal: ['@inertiajs/vue3'] },
        plugins: [
            {
                name: 'refactor-test-services',
                enforce: 'pre',
                resolveId(id) {
                    if (id === '@inertiajs/vue3') return '\0refactor:inertia';
                },
                load(id) {
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client = () => globalThis.__refactorApi;';
                    if (id === '\0refactor:inertia')
                        return `import { defineComponent, h } from 'vue';
                    export const usePage = () => globalThis.__refactorPage;
                    export const Head = defineComponent({ inheritAttrs: false, setup: (_, { slots }) => () => slots.default?.() });
                    export const Link = defineComponent({ setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) });`;
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
after(async () => {
    await server?.close();
    delete globalThis.__refactorApi;
    delete globalThis.__refactorPage;
});
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
const settle = async () => {
    await new Promise((resolve) => setImmediate(resolve));
    await nextTick();
};
function mount(factory) {
    let state;
    const app = renderer.createApp({
        setup() {
            state = factory();
            return () => h('div');
        },
    });
    app.provide(ssrContextKey, { modules: new Set() });
    app.mount({});
    return { app, state };
}
const deferred = () => {
    let resolve;
    const promise = new Promise((yes) => {
        resolve = yes;
    });
    return { promise, resolve };
};
const game = (id, season, home, away, extras = {}) => ({
    id,
    season,
    status: 'STATUS_FINAL',
    home_team_id: 1,
    away_team_id: 2,
    home_score: home,
    away_score: away,
    game_date: `2026-09-${String(20 - id).padStart(2, '0')}`,
    ...extras,
});
const teamConfig = {
    sport: 'nfl',
    sportLabel: 'NFL',
    predictionsHref: '/nfl/predictions',
    teamHref: (id) => `/nfl/teams/${id}`,
    headTitle: () => 'Team',
    showPowerRanking: true,
};
async function teamState(
    games,
    metrics = [{ team_id: 1, season: 2025, net_rating: 10 }],
) {
    const calls = [];
    globalThis.__refactorApi = {
        teams: {
            show: async () => ({ data: { id: 1 } }),
            metrics: async () => ({ data: { season: 2026 } }),
            games: async (_, __, options) => {
                calls.push(options.query);
                return { data: games };
            },
        },
        metrics: { teams: async () => ({ data: metrics }) },
    };
    const { useSportTeamData } = await server.ssrLoadModule(
        '/resources/js/composables/useSportTeamData.ts',
    );
    const result = mount(() =>
        useSportTeamData({ teamId: 1, config: teamConfig }),
    );
    await settle();
    return { ...result, calls };
}

test('empty current-season data never falls back to prior-season games or rankings', async () => {
    const { app, state, calls } = await teamState([game(1, 2025, 40, 10)]);
    try {
        assert.deepEqual(calls, [{ season: 2026, per_page: 200 }]);
        assert.deepEqual(state.recentGames.value, []);
        assert.deepEqual(state.upcomingGames.value, []);
        assert.equal(state.powerRanking.value, null);
        assert.deepEqual(state.record.value, {
            wins: 0,
            losses: 0,
            ties: 0,
            games: 0,
        });
    } finally {
        app.unmount();
    }
});

test('team records distinguish ties, shutouts, missing scores and other seasons', async () => {
    const { app, state } = await teamState([
        game(1, 2026, 10, 0),
        game(2, 2026, 7, 7),
        game(3, 2026, 0, 3),
        game(4, 2026, null, 10),
        game(5, 2026, 99, 0, { home_team_id: 3, away_team_id: 4 }),
        game(6, 2025, 40, 10),
    ]);
    try {
        assert.deepEqual(state.record.value, {
            wins: 1,
            losses: 1,
            ties: 1,
            games: 3,
        });
        assert.deepEqual(state.recentRecord.value, {
            wins: 1,
            losses: 1,
            ties: 1,
            games: 3,
        });
        assert.deepEqual(state.recentForm.value, ['W', 'T', 'L']);
        const { default: Card } = await server.ssrLoadModule(
            '/resources/js/components/sport-team/TeamPowerRecentCards.vue',
        );
        const html = await renderToString(
            createSSRApp(Card, {
                showRecentForm: true,
                powerRanking: null,
                teamMetrics: null,
                recentRecord: state.recentRecord.value,
                recentForm: state.recentForm.value,
            }),
        );
        assert.match(html.replace(/<!--.*?-->/g, ''), /1-1-1/);
        assert.match(html, /Last 3 Games/);
        assert.match(html, /bg-muted text-muted-foreground[^>]*>T</);
    } finally {
        app.unmount();
    }
});

function fakeTimers(run) {
    const originals = {
        setTimeout: globalThis.setTimeout,
        clearTimeout: globalThis.clearTimeout,
    };
    const timers = new Map();
    let id = 0;
    globalThis.setTimeout = (callback) => {
        timers.set(++id, callback);
        return id;
    };
    globalThis.clearTimeout = (key) => timers.delete(key);
    const tick = async () => {
        const [key, callback] = timers.entries().next().value;
        timers.delete(key);
        return callback();
    };
    return Promise.resolve()
        .then(() => run(timers, tick))
        .finally(() => Object.assign(globalThis, originals));
}

test('polling never overlaps slow requests, skips disabled ticks and stops on disposal', async () => {
    await fakeTimers(async (timers, tick) => {
        let calls = 0;
        let enabled = true;
        const pending = deferred();
        const scope = effectScope();
        const polling = scope.run(() =>
            useAsyncPolling(
                async () => {
                    calls++;
                    await pending.promise;
                },
                30_000,
                () => enabled,
            ),
        );
        polling.start();
        polling.start();
        assert.equal(timers.size, 1);
        const inFlight = tick();
        assert.equal(calls, 1);
        polling.start();
        assert.equal(timers.size, 0);
        pending.resolve();
        await inFlight;
        assert.equal(timers.size, 1);
        enabled = false;
        await tick();
        assert.equal(calls, 1);
        assert.equal(timers.size, 1);
        scope.stop();
        assert.equal(timers.size, 0);
        polling.start();
        assert.equal(timers.size, 0);
    });
});

test('disposing during a poll never schedules another request', async () => {
    await fakeTimers(async (timers, tick) => {
        const pending = deferred();
        const scope = effectScope();
        const polling = scope.run(() =>
            useAsyncPolling(() => pending.promise, 30_000),
        );
        polling.start();
        const inFlight = tick();
        scope.stop();
        pending.resolve();
        await inFlight;
        assert.equal(timers.size, 0);
    });
});

const team = (id) => ({
    id,
    name: `Team ${id}`,
    abbreviation: `T${id}`,
    logo: null,
});
const tournamentGame = (id, roundKey, awayId, homeId, extras = {}) => ({
    id,
    roundKey,
    roundLabel: roundKey,
    gameDate: '2027-03-20',
    gameTime: '12:00',
    awayTeam: team(awayId),
    homeTeam: team(homeId),
    awaySeed: 1,
    homeSeed: 16,
    region: 'East',
    status: 'STATUS_SCHEDULED',
    ...extras,
});
const regions = [
    {
        id: 'east',
        name: 'East',
        rounds: [
            {
                key: 'first_four',
                label: 'First Four',
                games: [
                    tournamentGame(1, 'first_four', 1, 2, { awaySeed: 16 }),
                ],
            },
            {
                key: 'round_of_64',
                label: 'Round of 64',
                games: [tournamentGame(2, 'round_of_64', 3, 4)],
            },
        ],
    },
];

async function bracketState() {
    const { useBracketTournament } = await server.ssrLoadModule(
        '/resources/js/composables/useBracketTournament.ts',
    );
    const { useBracketProgress } = await server.ssrLoadModule(
        '/resources/js/composables/useBracketProgress.ts',
    );
    const picks = ref({});
    const current = ref(null);
    const locked = ref(false);
    const scope = effectScope();
    const tournament = scope.run(() =>
        useBracketTournament({ regions }, picks, current, () => locked.value),
    );
    const progress = scope.run(() => useBracketProgress(tournament));
    return { scope, picks, current, locked, tournament, progress };
}

test('bracket selections advance through virtual rounds and locked brackets reject changes', async () => {
    const { scope, picks, locked, tournament, progress } = await bracketState();
    try {
        const first = tournament.bracketRegions.value.find(
            (region) => region.name === 'East',
        ).rounds[0].matchups[0];
        tournament.selectWinner(first, first.participants[0]);
        assert.equal(
            picks.value[first.id],
            first.participants[0].participant.id,
        );
        const east = tournament.bracketRegions.value.find(
            (region) => region.name === 'East',
        );
        assert.equal(
            east.rounds[1].matchups[0].participants[0].participant.id,
            picks.value[first.id],
        );
        locked.value = true;
        tournament.selectWinner(first, first.participants[1]);
        assert.equal(
            picks.value[first.id],
            first.participants[0].participant.id,
        );
        assert.equal(progress.roundMeta(east, 'round_of_64').picked, 1);
    } finally {
        scope.stop();
    }
});

test('bracket grading and all five button layouts retain labels and locked state', async () => {
    const { scope, current, locked, tournament, progress } =
        await bracketState();
    try {
        const matchup = tournament.standaloneRounds.value.firstFour[0];
        const slot = matchup.participants[0];
        current.value = {
            results: {
                [matchup.id]: {
                    picked_id: slot.participant.id,
                    status: 'correct',
                    points: 10,
                },
            },
        };
        locked.value = true;
        const { bracketBuilderKey } = await server.ssrLoadModule(
            '/resources/js/composables/useBracketBuilderContext.ts',
        );
        const { default: Button } = await server.ssrLoadModule(
            '/resources/js/components/bracket/BracketParticipantButton.vue',
        );
        const context = reactive({
            ...tournament,
            ...progress,
            isBracketLocked: locked,
        });
        for (const variant of [
            'regional-mobile',
            'regional-desktop',
            'semifinal',
            'championship',
            'first-four',
        ]) {
            const app = createSSRApp(Button, {
                matchup,
                participantSlot: slot,
                variant,
            });
            app.provide(bracketBuilderKey, context);
            const html = await renderToString(app);
            assert.match(html, /Team 1/);
            assert.match(html, /disabled/);
            assert.match(html, /border-emerald-300\/40/);
            if (['semifinal', 'championship'].includes(variant))
                assert.match(html, /Correct/);
            else assert.doesNotMatch(html, />Correct</);
        }
    } finally {
        scope.stop();
    }
});

test('prediction-card state follows replacement props and ignores obsolete tracking responses', async () => {
    const requests = [];
    globalThis.__refactorApi = {
        userBets: {
            index: (options) => {
                const pending = deferred();
                requests.push({ ...pending, options });
                return pending.promise;
            },
        },
    };
    const { usePredictionCardGame } = await server.ssrLoadModule(
        '/resources/js/composables/usePredictionCardGame.ts',
    );
    const { usePredictionTracking } = await server.ssrLoadModule(
        '/resources/js/composables/usePredictionTracking.ts',
    );
    const input = reactive({
        href: '/nfl/games/1',
        sport: 'nfl',
        prediction: {
            id: 1,
            game: {
                status: 'STATUS_SCHEDULED',
                home_team: { abbreviation: 'BUF', logo: 'buf.svg' },
                away_team: { abbreviation: 'NYJ' },
            },
        },
    });
    const scope = effectScope();
    const gameState = scope.run(() => usePredictionCardGame(input));
    const tracking = scope.run(() =>
        usePredictionTracking(input, gameState, ref(false)),
    );
    try {
        assert.equal(gameState.homeTeamLabel(), 'BUF');
        gameState.handleHomeLogoError();
        assert.equal(gameState.showHomeLogo(), false);
        assert.equal(requests.length, 1);
        input.prediction = {
            id: 2,
            game: {
                status: 'STATUS_SCHEDULED',
                home_team: { abbreviation: 'KC', logo: 'kc.svg' },
                away_team: { abbreviation: 'DEN' },
            },
        };
        await nextTick();
        assert.equal(gameState.homeTeamLabel(), 'KC');
        assert.equal(gameState.showHomeLogo(), true);
        assert.equal(requests[0].options.init.signal.aborted, true);
        requests[1].resolve({
            tracking: {
                markets: {},
                user_bets: [
                    { bet_type: 'spread', selection_side: 'home', id: 22 },
                ],
            },
        });
        await settle();
        requests[0].resolve({
            tracking: {
                markets: {},
                user_bets: [
                    { bet_type: 'spread', selection_side: 'home', id: 11 },
                ],
            },
        });
        await settle();
        assert.equal(
            tracking.trackedBetForOption({
                betType: 'spread',
                selectionSide: 'home',
            }).id,
            22,
        );
    } finally {
        scope.stop();
    }
});

test('guest brackets restore, save and reset local picks through the extracted state owner', async (t) => {
    t.mock.timers.enable({
        apis: ['Date'],
        now: new Date('2026-03-18T12:00:00Z'),
    });
    const { useMarchMadnessBracket } = await server.ssrLoadModule(
        '/resources/js/composables/useMarchMadnessBracket.ts',
    );
    const { tournament, scope } = await bracketState();
    const first = tournament.bracketRegions.value.find(
        (region) => region.name === 'East',
    ).rounds[0].matchups[0];
    scope.stop();
    const storage = new Map([
        [
            'march-madness-bracket-builder-v3',
            JSON.stringify({
                [first.id]: first.participants[0].participant.id,
                invalid: 'team:99',
            }),
        ],
    ]);
    const originals = {
        window: globalThis.window,
        localStorage: globalThis.localStorage,
    };
    globalThis.localStorage = {
        getItem: (key) => storage.get(key) ?? null,
        setItem: (key, value) => storage.set(key, value),
        removeItem: (key) => storage.delete(key),
    };
    globalThis.window = {
        localStorage: globalThis.localStorage,
        matchMedia: () => ({ matches: false }),
    };
    const { app, state } = mount(() => useMarchMadnessBracket({ regions }));
    try {
        await settle();
        assert.equal(state.pickedGames, 1);
        assert.equal(state.picks.invalid, undefined);
        const matchup = state.bracketRegions.find(
            (region) => region.name === 'East',
        ).rounds[0].matchups[0];
        state.selectWinner(matchup, matchup.participants[1]);
        await nextTick();
        assert.equal(
            JSON.parse(storage.get('march-madness-bracket-builder-v3'))[
                matchup.id
            ],
            matchup.participants[1].participant.id,
        );
        state.requestResetBracket();
        assert.equal(state.resetConfirmOpen, true);
        state.confirmResetBracket();
        await nextTick();
        assert.equal(state.resetConfirmOpen, false);
        assert.equal(state.pickedGames, 0);
        assert.deepEqual(
            JSON.parse(storage.get('march-madness-bracket-builder-v3')),
            {},
        );
    } finally {
        app.unmount();
        for (const [key, value] of Object.entries(originals)) {
            if (value === undefined) delete globalThis[key];
            else globalThis[key] = value;
        }
    }
});

test('the bracket page renders every extracted section with shared scoped state', async () => {
    const { default: Page } = await server.ssrLoadModule(
        '/resources/js/pages/MarchMadnessBracket.vue',
    );
    const html = await renderToString(createSSRApp(Page, { regions }));
    for (const label of [
        'Active bracket',
        'How It Works',
        'Final Four and Title Game',
        'First Four',
        'East',
        'West',
        'South',
        'Midwest',
        'Team 1',
        'Team 3',
    ]) {
        assert.ok(html.includes(label), label);
    }
    assert.match(html, /id="bracket-board"/);
    assert.match(html, /Local bracket/);
    assert.match(html, /disabled/);
    assert.doesNotMatch(html, /undefined|NaN/);
});

test('account brackets keep switching, metadata saves and pick autosave behavior', async (t) => {
    t.mock.timers.enable({
        apis: ['Date'],
        now: new Date('2026-03-18T12:00:00Z'),
    });
    const { useMarchMadnessBracket } = await server.ssrLoadModule(
        '/resources/js/composables/useMarchMadnessBracket.ts',
    );
    const { tournament, scope } = await bracketState();
    const matchup = tournament.bracketRegions.value.find(
        (region) => region.name === 'East',
    ).rounds[0].matchups[0];
    scope.stop();
    const saved = {
        id: 1,
        public_id: 'saved-1',
        name: 'Original',
        season: 2027,
        group_id: 5,
        picks: { [matchup.id]: matchup.participants[0].participant.id },
        results: {},
        lock_at: '2027-03-19T16:00:00Z',
    };
    const updates = [];
    globalThis.__refactorPage = { props: { auth: { user: { id: 1 } } } };
    globalThis.__refactorApi = {
        groups: {
            index: async () => ({
                data: [{ id: 5, name: 'Friends', owner_id: 1 }],
            }),
        },
        cbbBrackets: {
            index: async () => ({
                data: [
                    saved,
                    { ...saved, public_id: 'saved-2', name: 'Second' },
                ],
            }),
            leaderboard: async () => ({ data: [] }),
            update: async (id, payload) => {
                updates.push({ id, payload });
                return { data: { ...saved, ...payload, public_id: id } };
            },
        },
    };
    const originals = {
        window: globalThis.window,
        localStorage: globalThis.localStorage,
    };
    globalThis.localStorage = {
        getItem: () => null,
        setItem() {},
        removeItem() {},
    };
    try {
        await fakeTimers(async (timers, tick) => {
            globalThis.window = {
                localStorage: globalThis.localStorage,
                matchMedia: () => ({ matches: false }),
                setTimeout: globalThis.setTimeout,
                clearTimeout: globalThis.clearTimeout,
            };
            const { app, state } = mount(() =>
                useMarchMadnessBracket({ regions }),
            );
            try {
                await settle();
                assert.equal(state.activeBracketPublicId, 'saved-1');
                assert.equal(state.pickedGames, 1);
                state.switchBracket('saved-2');
                assert.equal(state.bracketNameDraft, 'Second');
                timers.clear();
                const first = state.bracketRegions.find(
                    (region) => region.name === 'East',
                ).rounds[0].matchups[0];
                state.selectWinner(first, first.participants[1]);
                await nextTick();
                assert.equal(timers.size, 1);
                await tick();
                assert.equal(updates[0].id, 'saved-2');
                assert.equal(
                    updates[0].payload.picks[first.id],
                    first.participants[1].participant.id,
                );
                state.bracketNameDraft = 'Renamed';
                await state.saveBracketMeta();
                assert.deepEqual(updates[1], {
                    id: 'saved-2',
                    payload: { name: 'Renamed', group_id: 5 },
                });
                assert.equal(state.activeBracketName, 'Renamed');
                assert.equal(state.pickedGames, 1);
            } finally {
                app.unmount();
            }
        });
    } finally {
        globalThis.__refactorPage = { props: { auth: { user: null } } };
        for (const [key, value] of Object.entries(originals)) {
            if (value === undefined) delete globalThis[key];
            else globalThis[key] = value;
        }
    }
});

test('collapsed cards cancel obsolete tracking and fetch the new game only on expansion', async () => {
    const requests = [];
    globalThis.__refactorApi = {
        userBets: {
            index: (options) => {
                const pending = deferred();
                requests.push({ ...pending, options });
                return pending.promise;
            },
        },
    };
    const { usePredictionCardGame } = await server.ssrLoadModule(
        '/resources/js/composables/usePredictionCardGame.ts',
    );
    const { usePredictionTracking } = await server.ssrLoadModule(
        '/resources/js/composables/usePredictionTracking.ts',
    );
    const input = reactive({
        href: '/',
        sport: 'nfl',
        compact: true,
        prediction: { id: 1, game: { status: 'STATUS_SCHEDULED' } },
    });
    const expanded = ref(false);
    const scope = effectScope();
    const state = scope.run(() =>
        usePredictionTracking(input, usePredictionCardGame(input), expanded),
    );
    try {
        assert.equal(requests.length, 0);
        expanded.value = true;
        await nextTick();
        assert.equal(requests.length, 1);
        state.setSaveDialogOpen(true);
        input.prediction = { id: 2, game: { status: 'STATUS_SCHEDULED' } };
        expanded.value = false;
        await nextTick();
        assert.equal(requests[0].options.init.signal.aborted, true);
        assert.equal(state.isSaveDialogOpen.value, false);
        requests[0].resolve({
            tracking: {
                markets: {},
                user_bets: [
                    { id: 1, bet_type: 'spread', selection_side: 'home' },
                ],
            },
        });
        await settle();
        assert.equal(
            state.trackedBetForOption({
                betType: 'spread',
                selectionSide: 'home',
            }),
            null,
        );
        expanded.value = true;
        await nextTick();
        assert.equal(requests.length, 2);
        assert.equal(requests[1].options.query.prediction_id, 2);
        scope.stop();
        assert.equal(requests[1].options.init.signal.aborted, true);
        requests[1].resolve(null);
    } finally {
        scope.stop();
    }
});
