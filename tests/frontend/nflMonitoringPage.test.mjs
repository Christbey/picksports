import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { effectScope, reactive, nextTick } from 'vue';
import { monitorProps } from './fixtures/adminPages.mjs';
let server, useNflModelMonitoring, presentation;
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
    ({ useNflModelMonitoring } = await server.ssrLoadModule(
        '/resources/js/composables/useNflModelMonitoring.ts',
    ));
    presentation = await server.ssrLoadModule(
        '/resources/js/lib/nflMonitoringPresentation.ts',
    );
});
after(async () => {
    await server?.close();
});
const scoped = (props) => {
    const scope = effectScope();
    return { scope, state: scope.run(() => useNflModelMonitoring(props)) };
};
test('monitor signal grades load lazily with duplicate requests suppressed until completion', () => {
    const calls = [];
    globalThis.__adminRouter = { reload: (options) => calls.push(options) };
    const { scope, state } = scoped(reactive(monitorProps()));
    try {
        state.loadSignalGrades('evaluation');
        assert.equal(calls.length, 0);
        state.loadSignalGrades('signals');
        state.loadSignalGrades('signals');
        assert.equal(calls.length, 1);
        assert.deepEqual(calls[0].only, [
            'signal_grades',
            'signal_report_generated_at',
        ]);
        assert.equal(state.signalGradesLoading.value, true);
        calls[0].onFinish();
        assert.equal(state.signalGradesLoading.value, false);
        state.refresh();
        assert.equal(calls.at(-1).only.includes('signal_grades'), false);
        assert.equal(state.refreshing.value, true);
        calls.at(-1).onFinish();
        assert.equal(state.refreshing.value, false);
    } finally {
        scope.stop();
        delete globalThis.__adminRouter;
    }
});
test('signal selection survives compatible reloads and falls back when the selected signal disappears', async () => {
    const category = (keys) => ({
        signal_type: 'reason_code',
        signals: keys.map((signal_key) => ({
            signal_type: 'reason_code',
            signal_key,
        })),
        windows: keys.map((signal_key) => ({ signal_key })),
    });
    const props = reactive(
        monitorProps({ signal_grades: [category(['a', 'b'])] }),
    );
    const { scope, state } = scoped(props);
    try {
        state.selectSignal({ signal_type: 'reason_code', signal_key: 'b' });
        assert.equal(state.activeSignalWindows.value[0].signal_key, 'b');
        props.signal_grades = [category(['b', 'c'])];
        await nextTick();
        assert.equal(state.selectedSignalKeys.value.reason_code, 'b');
        props.signal_grades = [category(['c'])];
        await nextTick();
        assert.equal(state.selectedSignalKeys.value.reason_code, 'c');
        assert.equal(
            state.signalIsSelected({
                signal_type: 'reason_code',
                signal_key: 'c',
            }),
            true,
        );
    } finally {
        scope.stop();
    }
});
test('artifact navigation and refresh preserve their existing routes and optional grade reload', () => {
    const gets = [],
        reloads = [];
    globalThis.__adminRouter = {
        get: (...args) => gets.push(args),
        reload: (options) => reloads.push(options),
    };
    const { scope, state } = scoped(
        reactive(
            monitorProps({
                signal_grades: [
                    { signal_type: 'reason_code', signals: [], windows: [] },
                ],
            }),
        ),
    );
    try {
        state.selectedArtifact.value = 'artifact-2';
        state.changeArtifact();
        assert.deepEqual(gets[0], [
            '/admin/nfl-model-monitoring',
            { artifact: 'artifact-2' },
            { preserveState: true, replace: true },
        ]);
        state.refresh();
        assert.equal(reloads[0].only.includes('signal_grades'), true);
    } finally {
        scope.stop();
        delete globalThis.__adminRouter;
    }
});
test('monitor formatting distinguishes missing and zero values and settled versus pending decisions', () => {
    assert.equal(presentation.formatPercent(null), 'N/A');
    assert.equal(presentation.formatPercent(0), '0.0%');
    assert.equal(presentation.formatSigned(0), '0.000');
    assert.equal(
        presentation.decisionLabel({ decision: null, settlement: null }),
        'Awaiting Decision',
    );
    assert.equal(
        presentation.decisionLabel({
            decision: { is_bet: true },
            settlement: null,
        }),
        'Tracking Bet',
    );
    assert.equal(
        presentation.decisionLabel({ settlement: { status: 'loss' } }),
        'Loss',
    );
    assert.equal(
        presentation.evaluationSeason({
            evaluation_season: 2026,
            test_season: 2025,
        }),
        2026,
    );
});

test('monitor retains pending and absent-artifact explanations without mounting report sections', async () => {
    const { default: Page } = await server.ssrLoadModule(
        '/resources/js/pages/Admin/NflModelMonitoring.vue',
    );
    for (const [state, message] of [
        ['pending', 'Report is being prepared'],
        ['ready', 'No NFL model artifacts registered'],
    ]) {
        const app = createSSRApp(
            Page,
            monitorProps({
                artifact: null,
                report_status: { state, generated_at: null, message: 'Status' },
            }),
        );
        app.config.warnHandler = (message) => {
            throw new Error(message);
        };
        const html = await renderToString(app);
        assert.match(html, new RegExp(message));
        assert.doesNotMatch(html, /Recent Shadow Ledger|Promotion Gates/);
    }
});
