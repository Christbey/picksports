import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

let server;
let Game;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'game-access-fixtures',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useViewerAccess.ts'))
                        return 'import { computed } from "vue"; export const useViewerAccess = () => ({ isAdmin: computed(() => globalThis.__researchAdmin) });';
                    if (id.endsWith('/composables/useNflDetailedGamePage.ts'))
                        return 'import { ref } from "vue"; export const useNflDetailedGamePage = () => ({ pageProps: ref({ game: { status: "STATUS_SCHEDULED" } }), predictionSectionProps: ref({}), analysisSectionProps: ref({}), recentSectionProps: ref({}), depthCharts: ref(null) });';
                    if (id.endsWith('/game-page/SportDetailedGamePage.vue'))
                        return '<template><div><slot name="afterHero"/><slot name="afterLinescore"/><slot name="afterTrends"/></div></template>';
                    if (id.endsWith('/game-page/NflResearchBrief.vue'))
                        return '<script setup>globalThis.__researchMounts++;</script><template><div>Research panel fixture</div></template>';
                    if (
                        id.includes('/components/game-page/') &&
                        id.endsWith('.vue')
                    )
                        return '<template><div/></template>';
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
    Game = (await server.ssrLoadModule('/resources/js/pages/NFL/Game.vue'))
        .default;
});
after(async () => {
    await server?.close();
    delete globalThis.__researchAdmin;
    delete globalThis.__researchMounts;
});

test('non-admins cannot see or mount Research even if the section is selected', async () => {
    globalThis.__researchAdmin = false;
    globalThis.__researchMounts = 0;
    const wrapper = {
        ...Game,
        setup(props, context) {
            const bindings = Game.setup(props, context);
            bindings.mobileSection.value = 'research';
            return bindings;
        },
    };
    const html = await renderToString(createSSRApp(wrapper, { gameId: 1781 }));
    assert.doesNotMatch(html, /Research|Research panel fixture/);
    assert.match(html, /grid-cols-3/);
    assert.equal(globalThis.__researchMounts, 0);
});

test('admins retain the Research tab and mounted panel', async () => {
    globalThis.__researchAdmin = true;
    globalThis.__researchMounts = 0;
    const html = await renderToString(createSSRApp(Game, { gameId: 1781 }));
    assert.match(html, /Research/);
    assert.match(html, /Research panel fixture/);
    assert.match(html, /grid-cols-4/);
    assert.equal(globalThis.__researchMounts, 1);
});
