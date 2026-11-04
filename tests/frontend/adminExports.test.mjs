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
