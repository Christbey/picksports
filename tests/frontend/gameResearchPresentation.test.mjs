import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

let server;
let Panel;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'research-read-only-client',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client = () => ({ games: { researchSummary: () => { throw new Error("SSR must not request research"); } } });';
                },
            },
            vue(),
        ],
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
        resolve: {
            alias: {
                '@': fileURLToPath(
                    new URL('../../resources/js', import.meta.url),
                ),
            },
        },
    });
    Panel = (
        await server.ssrLoadModule(
            '/resources/js/components/game-page/GameResearchPanel.vue',
        )
    ).default;
});
after(async () => server?.close());

async function render(overrides = {}) {
    const saved = {
        status: 'partial',
        applicability: 'current',
        assessed_at: null,
        supporting: [{ claim: 'Exact stored excerpt.', team_side: 'home' }],
        concerns: [],
        facts: [],
        unresolved: [],
        sources: [],
        season_evidence: {
            home: {
                sample_games: 4,
                wins: 3,
                losses: 1,
                ties: 0,
                average_margin: 2.5,
            },
        },
        review: {
            status: 'current',
            summary: 'Existing deterministic selection.',
            context_summary: 'Interpretation of stored evidence.',
        },
        ...overrides,
    };
    const wrapper = {
        ...Panel,
        setup(props, context) {
            const bindings = Panel.setup(props, context);
            bindings.research.value = saved;
            bindings.loading.value = false;
            return bindings;
        },
    };
    return renderToString(createSSRApp(wrapper, { sport: 'nba', gameId: 1 }));
}

test('current research separates authoritative summary from AI interpretation', async () => {
    const html = await render();
    assert.match(html, /Existing deterministic selection/);
    assert.match(html, /AI interpretation:/);
    assert.match(html, /Interpretation of stored evidence/);
    assert.match(html, /Home team/);
    assert.match(html, /4 completed games/);
    assert.match(html, /\+2.5 average scoring margin/);
});

test('outdated review prose is withheld and stale research is explicitly labeled', async () => {
    const html = await render({
        applicability: 'outdated',
        review: {
            status: 'outdated',
            summary: 'Obsolete selection',
            context_summary: 'Obsolete prose',
        },
    });
    assert.match(html, /Research needs reassessment/);
    assert.match(html, /may no longer describe the current matchup/);
    assert.doesNotMatch(html, /Obsolete selection|Obsolete prose/);
});

test('refresh action is offered only when the API permits it', async () => {
    assert.doesNotMatch(
        await render({ capabilities: { can_retry: false } }),
        /Refresh research and review/,
    );
    assert.match(
        await render({ capabilities: { can_retry: true } }),
        /Refresh research and review/,
    );
});
