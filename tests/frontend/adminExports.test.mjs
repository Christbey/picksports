import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { effectScope, reactive } from 'vue';
import { exportProps } from './fixtures/adminPages.mjs';
let server, useAdminExports, presentation;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [vue()],
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
        resolve: {
            alias: [
                {
                    find: '@/layouts/AppLayout.vue',
                    replacement: fileURLToPath(
                        new URL('./fixtures/adminLayout.mjs', import.meta.url),
                    ),
                },
                {
                    find: '@/layouts/settings/Layout.vue',
                    replacement: fileURLToPath(
                        new URL('./fixtures/adminLayout.mjs', import.meta.url),
                    ),
                },
                {
                    find: '@inertiajs/vue3',
                    replacement: fileURLToPath(
                        new URL('./fixtures/adminInertia.mjs', import.meta.url),
                    ),
                },
                {
                    find: '@',
                    replacement: fileURLToPath(
                        new URL('../../resources/js', import.meta.url),
                    ),
                },
            ],
        },
    });
    ({ useAdminExports } = await server.ssrLoadModule(
        '/resources/js/composables/useAdminExports.ts',
    ));
    presentation = await server.ssrLoadModule(
        '/resources/js/lib/adminExportPresentation.ts',
    );
});
after(async () => {
    await server?.close();
});
test('export filtering preserves tab-specific query fields and resets incompatible selections', () => {
    const calls = [];
    globalThis.__adminRouter = { get: (...args) => calls.push(args) };
    const scope = effectScope();
    const state = scope.run(() => useAdminExports(reactive(exportProps())));
    try {
        state.selectedDate.value = '2026-11-04';
        state.selectedGame.value = '1';
        state.selectedMarket.value = 'player_points';
        state.applyFilters();
        assert.deepEqual(calls.at(-1)[1], {
            sport: 'NBA',
            tab: 'props',
            date: '2026-11-04',
            game: '1',
            market: 'player_points',
        });
        assert.deepEqual(calls.at(-1)[2], {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
        state.onDateChange();
        assert.equal(state.selectedGame.value, '');
        state.setTab('futures');
        assert.equal(state.selectedDate.value, '');
        assert.equal(state.selectedMarket.value, '');
        state.selectedSeason.value = '2026';
        state.applyFilters();
        assert.deepEqual(calls.at(-1)[1], {
            sport: 'NBA',
            tab: 'futures',
            season: '2026',
        });
        state.clearFilters();
        assert.deepEqual(calls.at(-1)[1], { sport: 'NBA', tab: 'props' });
    } finally {
        scope.stop();
        delete globalThis.__adminRouter;
    }
});
test('export row formatting keeps zero values and Under hit counts without changing signal scoring', () => {
    const rec = exportProps().recommendations[0];
    const rows = presentation.buildStatRows(rec);
    assert.equal(rows.find((row) => row.key === 'season').value, '0.0');
    assert.equal(rows.find((row) => row.key === 'hit5').value, '3/5');
    assert.equal(presentation.formatStatNumber(null), 'N/A');
    assert.equal(presentation.normalizePercent(0.8), 80);
    assert.equal(presentation.normalizePercent(80), 80);
    assert.deepEqual(
        presentation.buildRelativeSignalMap([{ id: 1, value: 80 }]),
        { 1: 80 },
    );
});
test('relative signal maps update with incoming rows and the selected preset retains its dimensions', () => {
    const props = reactive(exportProps());
    const scope = effectScope();
    const state = scope.run(() => useAdminExports(props));
    try {
        assert.equal(state.signalForProp(props.recommendations[0]), 80);
        props.recommendations = [
            { ...props.recommendations[0], confidence: 90 },
        ];
        assert.equal(state.signalForProp(props.recommendations[0]), 90);
        assert.equal(
            state.presets.find((x) => x.id === 'instagram').height,
            1350,
        );
        assert.equal(
            state.presets.find((x) => x.id === 'facebook').width,
            1200,
        );
    } finally {
        scope.stop();
    }
});

test('bulk export always releases its busy state when the canvas is unavailable or drawing fails', async () => {
    const originalDocument = globalThis.document;
    const scope = effectScope();
    const state = scope.run(() => useAdminExports(exportProps()));
    try {
        for (const tab of ['props', 'predictions', 'futures', 'tournament']) {
            state.activeTab.value = tab;
            globalThis.document = {
                createElement: () => ({ getContext: () => null }),
            };
            await state.exportAllCurrent();
            assert.equal(state.exportingAll.value, false);
            globalThis.document = {
                createElement: () => {
                    throw new Error('Canvas failed');
                },
            };
            await assert.rejects(state.exportAllCurrent(), /Canvas failed/);
            assert.equal(state.exportingAll.value, false);
        }
    } finally {
        scope.stop();
        globalThis.document = originalDocument;
    }
});

test('every export tab retains its empty state and disables bulk export when no rows are loaded', async () => {
    const { default: Page } = await server.ssrLoadModule(
        '/resources/js/pages/Admin/PlayerPropExports.vue',
    );
    for (const [activeTab, message] of [
        ['props', 'No prop recommendations'],
        ['predictions', 'No game predictions'],
        ['futures', 'No futures'],
        ['tournament', 'No tournament'],
    ]) {
        const app = createSSRApp(
            Page,
            exportProps({
                activeTab,
                recommendations: [],
                predictions: [],
                futures: [],
                tournaments: [],
            }),
        );
        app.config.warnHandler = (message) => {
            throw new Error(message);
        };
        const html = await renderToString(app);
        assert.match(html, new RegExp(message));
        const bulkButton = [
            ...html.matchAll(/<button\b[^>]*>[\s\S]*?<\/button>/g),
        ]
            .map(([button]) => button)
            .find((button) => button.includes('Export All Shown'));
        assert.ok(bulkButton);
        assert.match(bulkButton, /\sdisabled(?:[=\s>])/);
    }
});

test('unknown export confidence stays outside relative ranking while genuine zero remains observed', () => {
    const props = reactive(exportProps());
    props.predictions = [
        {
            ...props.predictions[0],
            confidence: null,
            win_probability: null,
            pick_side: null,
            pick_team: null,
        },
    ];
    const scope = effectScope();
    const state = scope.run(() => useAdminExports(props));
    try {
        assert.equal(state.signalForPrediction(props.predictions[0]), null);
        props.predictions[0].confidence = 0;
        assert.equal(state.signalForPrediction(props.predictions[0]), 0);
        assert.equal(presentation.normalizePercent(null), null);
    } finally {
        scope.stop();
    }
});

test('rendered exports label the stored home probability explicitly', async () => {
    const { default: Page } = await server.ssrLoadModule(
        '/resources/js/pages/Admin/PlayerPropExports.vue',
    );
    const props = exportProps({ activeTab: 'predictions' });
    props.predictions[0].pick_side = 'Away';
    props.predictions[0].pick_team = 'Away';
    props.predictions[0].win_probability = 40;
    const html = await renderToString(createSSRApp(Page, props));
    assert.match(html, /Home win probability/);
    assert.match(html, /40\.0%/);
});

test('actual card drawing never turns missing confidence into a zero percent badge', async () => {
    const oldDocument = globalThis.document;
    const texts = [];
    const ctx = new Proxy(
        {},
        {
            get(target, key) {
                if (key === 'measureText')
                    return (text) => ({ width: String(text).length * 5 });
                if (key === 'fillText')
                    return (text) => texts.push(String(text));
                if (key === 'createLinearGradient')
                    return () => ({ addColorStop() {} });
                return () => {};
            },
        },
    );
    globalThis.document = {
        createElement: () => ({
            getContext: () => ctx,
            toBlob: (callback) => callback(null),
        }),
    };
    const props = exportProps();
    const prediction = {
        ...props.predictions[0],
        confidence: null,
        win_probability: null,
        pick_side: null,
        pick_team: null,
    };
    props.predictions = [prediction];
    const scope = effectScope();
    const state = scope.run(() => useAdminExports(props));
    try {
        await state.exportPrediction(prediction, 0);
        assert.ok(texts.includes('N/A'));
        assert.ok(!texts.includes('0%'));
        assert.ok(!texts.includes('0.0%'));
        assert.ok(!texts.some((text) => text.includes('null')));
    } finally {
        scope.stop();
        globalThis.document = oldDocument;
    }
});

test('rendered exports refuse an unverified split wall clock', async () => {
    const { default: Page } = await server.ssrLoadModule(
        '/resources/js/pages/Admin/PlayerPropExports.vue',
    );
    const html = await renderToString(
        createSSRApp(Page, exportProps({ activeTab: 'predictions' })),
    );
    assert.match(html, /Time unavailable/);
});

test('known relative ranking remains unchanged and missing rows cannot distort it', () => {
    const known = [
        { id: 1, value: 72 },
        { id: 2, value: 80 },
        { id: 'canonical-id', value: 85 },
    ];
    assert.deepEqual(presentation.buildRelativeSignalMap(known), {
        1: 48,
        2: 77,
        'canonical-id': 96,
    });
    assert.deepEqual(
        presentation.buildRelativeSignalMap([
            ...known,
            { id: 99, value: null },
        ]),
        presentation.buildRelativeSignalMap(known),
    );
    assert.equal(presentation.formatRelativeSignal(1), '1/100');
    assert.equal(presentation.formatRelativeSignal(null), 'N/A');
});

test('actual exports remain bound to received data while a different sport filter is selected', async () => {
    const oldDocument = globalThis.document;
    const oldCreate = URL.createObjectURL;
    const oldRevoke = URL.revokeObjectURL;
    const texts = [],
        downloads = [];
    const ctx = new Proxy(
        {},
        {
            get(target, key) {
                if (key === 'measureText')
                    return (text) => ({ width: String(text).length * 5 });
                if (key === 'fillText')
                    return (text) => texts.push(String(text));
                if (key === 'createLinearGradient')
                    return () => ({ addColorStop() {} });
                return () => {};
            },
        },
    );
    globalThis.document = {
        createElement: (tag) =>
            tag === 'canvas'
                ? {
                      getContext: () => ctx,
                      toBlob: (callback) => callback(new Blob(['synthetic'])),
                  }
                : {
                      click() {
                          downloads.push(this.download);
                      },
                      remove() {},
                  },
        body: { appendChild() {} },
    };
    URL.createObjectURL = () => 'blob:synthetic';
    URL.revokeObjectURL = () => {};
    const props = exportProps();
    const scope = effectScope();
    const state = scope.run(() => useAdminExports(props));
    try {
        state.selectedSport.value = 'MLB';
        await state.exportFuturesRow(props.futures[0], 0);
        assert.ok(texts.includes('NBA Futures'));
        assert.ok(!texts.includes('MLB Futures'));
        assert.match(downloads[0], /^NBA-futures-/);
    } finally {
        scope.stop();
        globalThis.document = oldDocument;
        URL.createObjectURL = oldCreate;
        URL.revokeObjectURL = oldRevoke;
    }
});

test('stored push outcomes are never counted as Under wins', () => {
    const record = { games: 5, hits: 2, over: 2, under: 1, pushes: 2 };
    assert.equal(
        presentation.recommendationHitCount('Under', record),
        '1/5 (2 pushes)',
    );
    assert.equal(
        presentation.recommendationHitCount('Under', { games: 5, hits: 2 }),
        'N/A',
    );
    assert.equal(
        presentation.recommendationHitCount('Under', {
            games: 5,
            hits: 5,
            under: 0,
            over: 5,
            pushes: 0,
        }),
        '0/5',
    );
});

test('actual export rows prefer the rich observed cover record over a sparse legacy complement', () => {
    const rec = exportProps().recommendations[0];
    rec.stats.cover_record = {
        last_5: { games: 5, hits: 2, over: 2, under: 1, pushes: 2 },
    };
    assert.equal(
        presentation.buildStatRows(rec).find((row) => row.key === 'hit5').value,
        '1/5 (2 pushes)',
    );
});

test('legacy decimal wire values stay visible without accepting malformed or nonfinite numbers', () => {
    assert.equal(presentation.formatStatNumber('1.8'), '1.8');
    assert.equal(presentation.formatStatNumber('-0.5'), '-0.5');
    assert.equal(presentation.formatStatNumber('0.0'), '0.0');
    assert.equal(presentation.normalizePercent('0.8'), 80);
    for (const value of ['', 'Infinity', '1e999', '0x10', false, []])
        assert.equal(presentation.formatStatNumber(value), 'N/A');
});

test('actual bulk drawing preserves unknown stats and a known low relative score without rescaling it', async () => {
    const oldDocument = globalThis.document;
    const texts = [];
    const ctx = new Proxy(
        {},
        {
            get(target, key) {
                if (key === 'measureText')
                    return (text) => ({ width: String(text).length * 5 });
                if (key === 'fillText')
                    return (text) => texts.push(String(text));
                if (key === 'createLinearGradient')
                    return () => ({ addColorStop() {} });
                return () => {};
            },
        },
    );
    globalThis.document = {
        createElement: () => ({
            getContext: () => ctx,
            toBlob: (callback) => callback(null),
        }),
    };
    const props = exportProps();
    props.recommendations[0].stats.season_avg = null;
    props.recommendations[0].stats.last5_avg = null;
    props.recommendations[0].edge = null;
    const scope = effectScope();
    const state = scope.run(() => useAdminExports(props));
    try {
        await state.exportAllCurrent();
        assert.ok(texts.filter((text) => text === 'N/A').length >= 3);
        assert.ok(!texts.includes('0'));
        texts.length = 0;
        props.predictions[0].confidence = 0.01;
        await state.exportPrediction(props.predictions[0], 0);
        assert.ok(texts.includes('1/100'));
        assert.ok(!texts.includes('100%'));
    } finally {
        scope.stop();
        globalThis.document = oldDocument;
    }
});

test('sport replacement cannot submit the previous sports game and market selectors', () => {
    const calls = [];
    globalThis.__adminRouter = { get: (...args) => calls.push(args) };
    const props = reactive(exportProps());
    const scope = effectScope();
    const state = scope.run(() => useAdminExports(props));
    try {
        state.selectedDate.value = '2026-11-04';
        state.selectedGame.value = '1';
        state.selectedMarket.value = 'player_points';
        state.selectedSeason.value = '2026';
        state.selectedSport.value = 'MLB';
        state.applyFilters();
        assert.deepEqual(calls.at(-1)[1], { sport: 'MLB', tab: 'props' });
        assert.equal(state.selectedGame.value, '');
        assert.equal(state.selectedMarket.value, '');
    } finally {
        scope.stop();
        delete globalThis.__adminRouter;
    }
});
