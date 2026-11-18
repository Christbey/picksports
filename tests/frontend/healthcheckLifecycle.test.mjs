import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

let server;
let presentation;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'healthcheck-context-fixture',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useHealthchecks.ts')) {
                        return 'export function useHealthchecksContext() { return globalThis.__healthcheckLifecycleContext; }';
                    }
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
    presentation = await server.ssrLoadModule(
        '/resources/js/lib/healthcheckPresentation.ts',
    );
});
after(async () => {
    delete globalThis.__healthcheckLifecycleContext;
    await server?.close();
});

test('labels completed checks separately from failed or interrupted execution', () => {
    const label = presentation.validationExecutionLabel;
    assert.equal(
        label({ execution_status: 'completed' }, 'failing'),
        'Full checks · Checks completed',
    );
    assert.match(
        label(
            { validation_scope: 'data', execution_status: 'failed' },
            'passing',
        ),
        /Data-only checks · Execution failed/,
    );
    assert.match(
        label(null, 'interrupted'),
        /Interrupted; findings may be incomplete/,
    );
    assert.match(label({ execution_status: 'running' }, 'running'), /Running/);
    assert.match(label(null, 'passing'), /Execution status unavailable/);
});

test('renders failing findings alongside completed execution without calling them healthy', async () => {
    const run = {
        id: 1,
        scope: 'sport:nba',
        status: 'failing',
        completed_at: null,
        summary: {
            failing: 2,
            warning: 0,
            passing: 1,
            validation_scope: 'full',
            execution_status: 'completed',
        },
        findings: [],
    };
    globalThis.__healthcheckLifecycleContext = {
        selectedView: 'validation',
        recent_validation_runs: [run],
        selected_validation_run: run,
        validation_trend: null,
        getStatusColor: () => '',
        formatStatus: (v) => v,
    };
    const { default: History } = await server.ssrLoadModule(
        '/resources/js/components/admin/HealthcheckValidationHistory.vue',
    );
    const html = await renderToString(createSSRApp(History));
    assert.match(html, /Checks completed/);
    assert.match(html, /2 failing/);
    assert.doesNotMatch(html, /healthy/i);
    run.summary.execution_status = 'failed';
    const failed = await renderToString(createSSRApp(History));
    assert.match(failed, /Execution failed; review saved findings/);
});
