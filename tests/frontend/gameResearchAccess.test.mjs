import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

let server;
let Section;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'shared-research-access-fixture',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useViewerAccess.ts'))
                        return 'import { computed } from "vue"; export const useViewerAccess = () => ({ isAdmin: computed(() => globalThis.__sharedResearchAdmin) });';
                    if (id.endsWith('/game-page/GameResearchPanel.vue'))
                        return '<script setup>globalThis.__sharedResearchMounts++;</script><template><div>Saved research fixture</div></template>';
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
    Section = (
        await server.ssrLoadModule(
            '/resources/js/components/game-page/GameResearchSection.vue',
        )
    ).default;
});
after(async () => {
    await server?.close();
    delete globalThis.__sharedResearchAdmin;
    delete globalThis.__sharedResearchMounts;
});

test('subscriber cannot mount shared research even if disclosure state is opened', async () => {
    globalThis.__sharedResearchAdmin = false;
    globalThis.__sharedResearchMounts = 0;
    const wrapper = {
        ...Section,
        setup(props, context) {
            const bindings = Section.setup(props, context);
            bindings.opened.value = true;
            return bindings;
        },
    };
    const html = await renderToString(
        createSSRApp(wrapper, { sport: 'nba', gameId: 1 }),
    );
    assert.doesNotMatch(html, /Matchup research|Saved research/);
    assert.equal(globalThis.__sharedResearchMounts, 0);
});

test('admin disclosure is lazy and does not mount research during ordinary navigation', async () => {
    globalThis.__sharedResearchAdmin = true;
    globalThis.__sharedResearchMounts = 0;
    const html = await renderToString(
        createSSRApp(Section, { sport: 'nba', gameId: 1 }),
    );
    assert.match(html, /Matchup research/);
    assert.equal(globalThis.__sharedResearchMounts, 0);
});

test('admin opened disclosure mounts one shared panel', async () => {
    globalThis.__sharedResearchAdmin = true;
    globalThis.__sharedResearchMounts = 0;
    const wrapper = {
        ...Section,
        setup(props, context) {
            const bindings = Section.setup(props, context);
            bindings.opened.value = true;
            return bindings;
        },
    };
    const html = await renderToString(
        createSSRApp(wrapper, { sport: 'mlb', gameId: 1 }),
    );
    assert.match(html, /Saved research fixture/);
    assert.equal(globalThis.__sharedResearchMounts, 1);
});
