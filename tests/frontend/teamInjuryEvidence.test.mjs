import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { fileURLToPath } from 'node:url';
let server;
before(async () => {
    server = await createServer({
        configFile: false,
        ssr: { noExternal: ['@inertiajs/vue3'] },
        plugins: [
            {
                name: 'injury-evidence-fakes',
                enforce: 'pre',
                resolveId(id) {
                    if (id === '@inertiajs/vue3') return '\0injury-inertia';
                },
                load(id) {
                    if (id === '\0injury-inertia')
                        return `export const Head={setup(){return()=>null;}};`;
                    if (id.endsWith('/layouts/AppLayout.vue'))
                        return `<script>export default {inheritAttrs:false,setup(_, {slots}){return()=>slots.default?.();}};</script>`;
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client=()=>globalThis.__injuryEvidenceApi;';
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
after(() => server?.close());
test('actual compiled injuries board labels unknown observation and stored clocks without claiming a successful sync', async () => {
    const payload = {
        data: [
            {
                id: 1,
                player_id: 1,
                team_id: 1,
                status: 'Out',
                player_name: 'Synthetic Player',
                team_abbreviation: 'TST',
                source_updated_at: '2026-10-01 12:00:00',
            },
        ],
        meta: {
            freshness: {
                source_clock_status: 'unverified_storage_clock',
                is_stale: null,
                last_observed_at: null,
                last_stored_at: '2026-10-10 12:00:00',
                unverified_rows: 1,
            },
            warnings: ['Observation freshness is unavailable.'],
        },
    };
    globalThis.__injuryEvidenceApi = {
        injuries: { index: async () => payload },
    };
    const Component = (
        await server.ssrLoadModule('/resources/js/components/SportInjuries.vue')
    ).default;
    const setup = Component.setup;
    Component.setup = (props, context) => {
        const state = setup(props, context);
        // Inject response state at the presentation boundary; actual HTTP contract is tested in Pest.
        state.rows.value = payload.data;
        state.freshness.value = payload.meta.freshness;
        state.warnings.value = payload.meta.warnings;
        return state;
    };
    try {
        const output = await renderToString(
            createSSRApp(Component, {
                config: {
                    sport: 'nba',
                    title: 'NBA injuries',
                    subtitle: 'Stored observations',
                    breadcrumbHref: '/nba/injuries',
                },
            }),
        );
        assert.match(output, /Observation freshness (?:is )?unavailable/i);
        assert.doesNotMatch(output, /Last successful sync/);
        assert.match(
            output,
            /stored.*clock.*unverified|unverified.*stored.*clock/i,
        );
        assert.match(output, /2026-10-01 12:00:00/);
    } finally {
        Component.setup = setup;
        delete globalThis.__injuryEvidenceApi;
    }
});
