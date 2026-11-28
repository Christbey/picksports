import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { mkdtempSync, rmSync, appendFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp, h, provide, reactive } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { monitorProps } from './fixtures/adminPages.mjs';

let server, Section, Tabs, context, cacheDir;
before(async () => {
    cacheDir = mkdtempSync(join(tmpdir(), 'nfl-monitor-market-'));
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
    ({ default: Section } = await server.ssrLoadModule(
        '/resources/js/components/admin/NflMonitoringObservations.vue',
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
    if (cacheDir) rmSync(cacheDir, { recursive: true, force: true });
});

function observation(
    market,
    baseline,
    challenger,
    probability = 0.6,
    challengerProbability = 0.65,
) {
    return {
        id: 101,
        game_id: 9001,
        matchup: 'Synthetic Away @ Synthetic Home',
        game_date: '2026-11-15',
        game_time: '12:00:00',
        game_status: 'STATUS_FINAL',
        market_type: market,
        baseline_output: baseline,
        challenger_output: challenger,
        output_delta: challenger - baseline,
        baseline_outputs: {
            win_probability: probability,
            predicted_spread: -3.5,
            predicted_total: 44.5,
        },
        challenger_outputs: {
            win_probability: challengerProbability,
            predicted_spread: -1.5,
            predicted_total: 46.5,
            uncertainty: null,
        },
        active_source: 'baseline',
        public_output_changed: false,
        generated_at: '2026-11-15T16:00:00Z',
        snapshot: {
            id: 41,
            snapshot_run_id: null,
            model_run_id: null,
            feature_hash: 'synthetic-feature',
            pregame_safe: true,
            availability_status: 'available',
            features_available_at: '2026-11-15T16:00:00Z',
            game_start_at: '2026-11-15T18:00:00Z',
        },
        inference_run_id: 'synthetic-run',
        decision: null,
        settlement: null,
    };
}

async function renderedWinCell(row) {
    const Root = {
        setup() {
            const props = reactive(
                monitorProps({
                    observations: [row],
                    report_status: {
                        state: 'ready',
                        generated_at: null,
                        message: 'Stored report',
                    },
                }),
            );
            provide(
                context.nflMonitoringKey,
                context.useNflModelMonitoring(props),
            );
            return () =>
                h(
                    Tabs,
                    { defaultValue: 'observations' },
                    { default: () => h(Section) },
                );
        },
    };
    const app = createSSRApp(Root);
    app.config.warnHandler = (message) => {
        throw new Error(message);
    };
    const html = await renderToString(app);
    assert.match(html, /Win Probability/);
    const body = html.match(/<tbody\b[^>]*>([\s\S]*?)<\/tbody>/)?.[1];
    assert.ok(body, html);
    const cells = [...body.matchAll(/<td\b[^>]*>([\s\S]*?)<\/td>/g)];
    assert.equal(cells.length, 6, html);
    const cell = cells[1][1]
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    if (process.env.REVIEW_NFL_MONITOR_TRACE) {
        appendFileSync(
            process.env.REVIEW_NFL_MONITOR_TRACE,
            JSON.stringify({ row, html, winProbabilityCell: cell }) + '\n',
            { mode: 0o600 },
        );
    }
    return cell;
}

for (const [title, row, expected] of [
    [
        'win-probability row keeps actual observed percentages',
        observation('win_probability', 0.6, 0.65),
        ['60.0%', '65.0%'],
    ],
    [
        'genuine zero win probabilities remain zero',
        observation('win_probability', 0, 0, 0, 0),
        ['0.0%', '0.0%'],
    ],
    [
        'spread row uses recorded win probabilities instead of point margins as percentages',
        observation('spread', -3.5, -1.5),
        ['60.0%', '65.0%'],
    ],
    [
        'total row uses recorded win probabilities instead of points as percentages',
        observation('total', 44.5, 46.5),
        ['60.0%', '65.0%'],
    ],
]) {
    test(title, async () => {
        const cell = await renderedWinCell(row);
        for (const value of expected) assert.ok(cell.includes(value), cell);
        assert.doesNotMatch(cell, /-350\.0%|-150\.0%|4450\.0%|4650\.0%/);
    });
}

for (const [market, baseline, challenger] of [
    ['spread', -3.5, -1.5],
    ['total', 44.5, 46.5],
]) {
    test(`${market} row missing recorded win probabilities stays unavailable with point delta labeled`, async () => {
        const row = observation(market, baseline, challenger, null, null);
        const cell = await renderedWinCell(row);
        assert.equal((cell.match(/N\/A/g) ?? []).length, 2, cell);
        assert.doesNotMatch(cell, /-?\d+(?:\.\d+)?%/);
        const label = market[0].toUpperCase() + market.slice(1);
        assert.ok(cell.includes(`${label} delta (points)`), cell);
        assert.ok(cell.includes('2.000'), cell);
    });
}
