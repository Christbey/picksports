import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import {
    createSSRApp,
    createRenderer,
    h,
    ref,
    nextTick,
    reactive,
    ssrContextKey,
} from 'vue';
import { renderToString } from 'vue/server-renderer';

let server, Shell;
before(async () => {
    server = await createServer({
        configFile: false,
        ssr: { noExternal: ['@inertiajs/vue3'] },
        plugins: [
            {
                name: 'props-shell-fixtures',
                enforce: 'pre',
                resolveId(id) {
                    if (id === '@inertiajs/vue3') return '\0props-inertia';
                },
                load(id) {
                    if (id.endsWith('/layouts/AppLayout.vue'))
                        return '<template><div><slot /></div></template>';
                    if (id.endsWith('/composables/useViewerAccess.ts'))
                        return `import { inject, computed } from 'vue'; export const useViewerAccess = () => { const admin = inject('props-test-admin', false); return { isAdmin: computed(() => admin) }; };`;
                    if (id === '\0props-inertia')
                        return `import { defineComponent, h } from 'vue'; export const Link = defineComponent({ setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) });`;
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
    ({ default: Shell } = await server.ssrLoadModule(
        '/resources/js/components/player-props/SportPlayerPropsShell.vue',
    ));
});
after(async () => server?.close());
const rec = {
    id: 7,
    player: {
        id: 3,
        name: 'Test Player',
        team: 'BUF',
        position: 'QB',
        url: null,
        headshot: null,
    },
    recommendation: 'Under',
    line: 13.5,
    market: 'Rushing Attempts',
    odds: -110,
    bookmaker: 'Test book',
    confidence: 80,
    model_over_probability: 30,
    market_over_probability: 48,
    edge_probability: 18,
    fetched_at: '2026-09-20T15:10:00Z',
    freshness_hours: 36,
    game: {
        home_team: 'BUF',
        away_team: 'NYJ',
        starts_at: '2026-09-20T17:00:00Z',
        kickoff_label: 'Sep 20, 12:00 PM CDT',
        timezone: 'America/Chicago',
    },
    stats: {
        season_avg: 0,
        historical_avg: 10,
        recent_avg: 9,
        last5_avg: 8,
        cover_record: null,
        consistency: null,
        vs_opponent_avg: null,
    },
    reasoning: [],
    streak: null,
    actual_value: 0,
    graded_at: '2026-09-20T20:00:00Z',
    hit_over: false,
};
const propsFor = (sport = 'NFL') => ({
    sport,
    sportSlug: sport.toLowerCase(),
    sportLabel: sport,
    description: 'Stored recommendations',
    filters: { date: null, game: null, market: null },
});
const stateFor = (data = [rec]) => ({
    loading: false,
    recommendations: data,
    dates: [{ value: '2026-11-08', label: 'Nov 8' }],
    games: [{ id: 10, label: 'NYJ @ BUF', date: '2026-11-08' }],
    markets: [{ value: 'player_pass_yds', label: 'Passing yards' }],
});
async function render({
    sport = 'NFL',
    state = stateFor(),
    admin = false,
} = {}) {
    const wrapper = {
        ...Shell,
        setup(props, context) {
            const bindings = Shell.setup(props, context);
            Object.assign(bindings.board, state);
            return bindings;
        },
    };
    const app = createSSRApp(wrapper, propsFor(sport));
    app.provide('props-test-admin', admin);
    return renderToString(app);
}
const text = (html) =>
    html
        .replace(/<!--.*?-->/gs, '')
        .replace(/<[^>]+>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

test('extracted shell preserves filter controls, NFL identity, prices and collapsed research', async () => {
    const html = await render();
    for (const value of [
        'NFL Player Props',
        'Test Player',
        'Under 13.5',
        '-110',
        'Test book',
        'Research &amp; history',
        'Recommendations',
        'All props',
    ])
        assert.ok(html.includes(value), value);
    for (const name of ['date', 'game', 'market'])
        assert.ok(html.includes(`id="${name}"`), name);
    assert.match(html, /Nov 8/);
    assert.match(html, /NYJ @ BUF/);
    assert.match(html, /Passing yards/);
    assert.doesNotMatch(html, /<details[^>]*\bopen\b/);
    assert.doesNotMatch(html, /Model details/);
});

test('non-NFL cards retain their layout, zero-valued results and collapsible statistics', async () => {
    for (const sport of ['NBA', 'WNBA', 'MLB', 'CBB', 'CFB']) {
        const html = await render({ sport });
        assert.match(html, /Test Player/);
        assert.match(html, /columns-1 gap-5 md:columns-2 lg:columns-3/);
        assert.match(text(html), /Under 13.5/);
        assert.match(text(html), /Actual: 0.0/);
        assert.match(text(html), /Won/);
        assert.match(text(html), /Show Stats/);
        assert.doesNotMatch(html, /History against this line|All quoted lines/);
        assert.doesNotMatch(html, /aria-label="Prop view"/);
    }
});

test('loading, failure, stale-data and empty-search states remain distinct', async () => {
    assert.match(
        await render({ state: { loading: true, recommendations: [] } }),
        /Loading player props/,
    );
    const failed = await render({
        state: { loading: false, recommendations: [], error: 'Offline' },
    });
    assert.match(failed, /Unable To Load Player Props/);
    assert.match(failed, /Offline/);
    assert.match(failed, /role="alert"/);
    assert.match(failed, /Try again/);
    assert.doesNotMatch(failed, /Test Player|No Recommendations Available/);
    const stale = await render({ state: { ...stateFor(), error: 'Offline' } });
    assert.match(stale, /Showing the previous results/);
    assert.match(stale, /Test Player/);
    assert.match(stale, /role="status"/);
    assert.match(stale, /Try again/);
    const empty = await render({
        state: {
            ...stateFor([]),
            boardMeta: { diagnostics: { raw_prop_count: 0 } },
        },
    });
    assert.match(empty, /No Props Synced For This Filter/);
    const search = await render({
        state: { ...stateFor(), playerSearch: 'does not exist' },
    });
    assert.match(search, /No players match your search/);
    assert.doesNotMatch(search, /id="prop-7"/);
});

test('expanded generic research retains public statistics and admin-only quote diagnostics', async () => {
    const { default: Card } = await server.ssrLoadModule(
        '/resources/js/components/player-props/PlayerPropCard.vue',
    );
    const { usePlayerPropPresentation } = await server.ssrLoadModule(
        '/resources/js/composables/usePlayerPropPresentation.ts',
    );
    const presentation = usePlayerPropPresentation(
        { sport: 'NBA' },
        ref(Date.parse('2026-09-20T16:00:00Z')),
    );
    const publicHtml = await renderToString(
        createSSRApp(Card, {
            rec,
            sport: 'NBA',
            isAdmin: false,
            expanded: true,
            presentation,
        }),
    );
    assert.match(publicHtml, /Historical Avg/);
    assert.match(publicHtml, /Probability edge/);
    assert.match(publicHtml, /52.0%/);
    assert.doesNotMatch(publicHtml, /Within 36-hour quote window/);
    const adminHtml = await renderToString(
        createSSRApp(Card, {
            rec,
            sport: 'NBA',
            isAdmin: true,
            expanded: true,
            presentation,
        }),
    );
    assert.match(adminHtml, /Within 36-hour quote window/);
    assert.match(adminHtml, /52.0%/);
    assert.match(adminHtml, /\+18.0 pp/);
});

test('all-quotes browser waits for filter defaults and starts exactly once when enabled', async () => {
    const originalFetch = globalThis.fetch;
    const requests = [];
    globalThis.fetch = async (url) => {
        requests.push(String(url));
        return new Response(
            JSON.stringify({
                data: [],
                meta: { pagination: { last_page: 1, total: 0 } },
            }),
            { headers: { 'Content-Type': 'application/json' } },
        );
    };
    try {
        const { default: Browser } = await server.ssrLoadModule(
            '/resources/js/components/player-props/NflAllPropsBrowser.vue',
        );
        let bindings;
        const input = reactive({
            date: '',
            game: '',
            market: '',
            enabled: false,
        });
        const wrapper = {
            props: Browser.props,
            setup(props, context) {
                bindings = Browser.setup(props, context);
                return () => h('div');
            },
        };
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
        const app = renderer.createApp({
            setup: () => () => h(wrapper, input),
        });
        app.provide(ssrContextKey, { modules: new Set() });
        app.mount({});
        try {
            assert.equal(requests.length, 0);
            assert.equal(bindings.loading.value, true);
            input.date = '2026-11-08';
            input.enabled = true;
            await nextTick();
            await new Promise((resolve) => setImmediate(resolve));
            assert.equal(requests.length, 1);
            assert.equal(
                new URL(
                    requests[0],
                    'https://picksports.test',
                ).searchParams.get('date'),
                '2026-11-08',
            );
            assert.deepEqual(bindings.rows.value, []);
        } finally {
            app.unmount();
        }
    } finally {
        globalThis.fetch = originalFetch;
    }
});
