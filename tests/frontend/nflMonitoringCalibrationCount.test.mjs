import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { mkdtemp, rm, appendFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp, h, provide, reactive } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { monitorProps } from './fixtures/adminPages.mjs';

let server, cacheDir, Evaluation, Tabs, context;
before(async () => {
    cacheDir = await mkdtemp(join(tmpdir(), 'nfl-calibration-count-'));
    server = await createServer({
        configFile: false,
        cacheDir,
        plugins: [vue()],
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
        resolve: {
            alias: [
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
    ({ default: Evaluation } = await server.ssrLoadModule(
        '/resources/js/components/admin/NflMonitoringEvaluation.vue',
    ));
    ({ Tabs } = await server.ssrLoadModule(
        '/resources/js/components/ui/tabs/index.ts',
    ));
    context = await server.ssrLoadModule(
        '/resources/js/composables/useNflModelMonitoring.ts',
    );
});
after(async () => {
    await server?.close();
    if (cacheDir) await rm(cacheDir, { recursive: true, force: true });
});

test('monitoring probability statistic labels its eligible observation count without claiming unique games', async () => {
    const props = reactive(
        monitorProps({
            summary: { ...monitorProps().summary, calibration_games: 2 },
            evaluation_windows: [],
        }),
    );
    const Root = {
        setup() {
            provide(
                context.nflMonitoringKey,
                context.useNflModelMonitoring(props),
            );
            return () =>
                h(
                    Tabs,
                    { defaultValue: 'evaluation' },
                    { default: () => h(Evaluation) },
                );
        },
    };
    const app = createSSRApp(Root);
    app.config.warnHandler = (message) => {
        throw new Error(message);
    };
    const html = await renderToString(app);
    const text = html
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    if (process.env.REVIEW_NFL_CALIBRATION_LABEL_TRACE) {
        await appendFile(
            process.env.REVIEW_NFL_CALIBRATION_LABEL_TRACE,
            JSON.stringify({ summary: props.summary, html, text }) + '\n',
            { mode: 0o600 },
        );
    }
    assert.match(text, /Live Brier Delta/);
    assert.match(text, /2 home-win probability observations/);
    assert.doesNotMatch(text, /2 games/);
});
