import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
let server, Insights, flatten, cacheDir;
before(async () => {
    cacheDir = await mkdtemp(join(tmpdir(), 'picksports-top-performer-'));
    server = await createServer({
        configFile: false,
        cacheDir,
        plugins: [vue()],
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
    Insights = (
        await server.ssrLoadModule(
            '/resources/js/components/game-page/BasketballGameInsights.vue',
        )
    ).default;
    ({ flattenApiV2Stats: flatten } = await server.ssrLoadModule(
        '/resources/js/composables/useApiV2StatsAdapter.ts',
    ));
});
after(async () => {
    try {
        await server?.close();
    } finally {
        if (cacheDir) await rm(cacheDir, { recursive: true, force: true });
    }
});
const text = (s) =>
    s
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
async function render(
    stats,
    mode = 'table',
    status = 'STATUS_FINAL',
    player = {},
) {
    const performers = flatten([
        {
            id: 1,
            player_id: 2,
            team_id: 3,
            player,
            team: { abbreviation: 'AWY' },
            stats,
        },
    ]);
    return renderToString(
        createSSRApp(Insights, {
            gameStatus: status,
            topPerformers: performers,
            performersMode: mode,
            homeTeamId: 4,
            homeLabel: 'HOME',
            awayLabel: 'AWAY',
            getBetterValue: () => null,
            formatNumber: (x) => String(x),
            showMetrics: false,
        }),
    );
}
for (const mode of ['table', 'list']) {
    test(`actual final basketball ${mode} preserves missing top-performer observations`, async () => {
        for (const stats of [
            {},
            {
                points: null,
                rebounds_total: null,
                assists: null,
                field_goals_made: null,
                field_goals_attempted: null,
            },
        ]) {
            const html = await render(stats, mode);
            assert.match(text(html), /Player #2/);
            assert.equal((html.match(/Unavailable/g) ?? []).length, 5);
            assert.doesNotMatch(text(html), /0-0/);
        }
    });
    test(`actual final basketball ${mode} preserves partial counts and real zero`, async () => {
        const html = await render(
            { points: 12, assists: 0, field_goals_made: 2 },
            mode,
        );
        assert.match(text(html), /Player #2/);
        assert.match(text(html), /2-Unavailable/);
        assert.ok((html.match(/Unavailable/g) ?? []).length >= 2);
        assert.match(html, />0<\//);
    });
}
test('actual parent keeps known-zero shooting and hides pending-game impact players', async () => {
    const html = await render({
        points: 0,
        rebounds_total: 0,
        assists: 0,
        field_goals_made: 0,
        field_goals_attempted: 0,
    });
    assert.doesNotMatch(html, /Unavailable/);
    const cells = [...html.matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((x) =>
        text(x[1]),
    );
    assert.deepEqual(cells.slice(2), ['0', '0', '0', '0-0']);
    assert.doesNotMatch(
        await render({}, 'table', 'STATUS_SCHEDULED'),
        /Top Performers|Synthetic Player/,
    );
});

test('actual V2 display name reaches final performer card while legacy name and ID fallback stay available', async () => {
    assert.match(
        text(
            await render({ points: 1 }, 'table', 'STATUS_FINAL', {
                display_name: 'Observed V2 Name',
                name: 'Legacy Name',
            }),
        ),
        /Observed V2 Name/,
    );
    assert.match(
        text(
            await render({ points: 1 }, 'table', 'STATUS_FINAL', {
                name: 'Legacy Name',
            }),
        ),
        /Legacy Name/,
    );
    assert.match(text(await render({ points: 1 })), /Player #2/);
});
