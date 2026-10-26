import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

let server;
let component;
before(async () => {
    server = await createServer({
        configFile: false,
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
    component = (
        await server.ssrLoadModule(
            '/resources/js/components/game-page/NflPositionEpaMatchups.vue',
        )
    ).default;
});
after(async () => {
    await server?.close();
});

const sample = (extra = {}) => ({
    eligible: true,
    epa_per_play: -0.1,
    rank: null,
    plays: 40,
    games: 3,
    league_teams: 20,
    ...extra,
});
const render = (defense = sample()) =>
    renderToString(
        createSSRApp(component, {
            teamName: (id) => (id === 1 ? 'SEA' : 'KC'),
            data: {
                season: 2026,
                directions: [
                    {
                        offense_team_id: 1,
                        defense_team_id: 2,
                        splits: [
                            {
                                split: 'TE',
                                offense: sample(),
                                defense,
                                previous_opponents: {
                                    games: 2,
                                    weighted_epa_allowed: 0.05,
                                },
                            },
                        ],
                    },
                ],
            },
        }),
    );
test('shows position EPA and prior defenses without inventing league ranks or individual grades', async () => {
    const html = await render();
    assert.match(html, /SEA offense vs KC defense/);
    assert.match(html, /TE targets/);
    assert.match(html, /-0.100 · rank unavailable/);
    assert.match(html, /0.050 EPA allowed/);
    assert.match(html, /2\/3 comparable games/);
    assert.match(html, /not individual coverage grades/);
});
test('incomplete position evidence is withheld while mapping coverage remains visible', async () => {
    const html = await render(
        sample({
            eligible: false,
            epa_per_play: 9.9,
            target_mapping_coverage: 0.5,
            reason: 'Missing position mapping',
        }),
    );
    assert.match(html, /Insufficient coverage/);
    assert.match(html, /Position mapping: 50%/);
    assert.match(html, /Missing position mapping/);
    assert.doesNotMatch(html, /9.900/);
});
