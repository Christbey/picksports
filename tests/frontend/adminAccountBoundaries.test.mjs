import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
let server, Users;
before(async () => {
    const fixture = (name) =>
        fileURLToPath(new URL(`./fixtures/${name}`, import.meta.url));
    server = await createServer({
        configFile: false,
        plugins: [vue()],
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
        resolve: {
            alias: [
                {
                    find: '@/layouts/AppLayout.vue',
                    replacement: fixture('adminLayout.mjs'),
                },
                {
                    find: '@/layouts/settings/Layout.vue',
                    replacement: fixture('adminLayout.mjs'),
                },
                {
                    find: '@inertiajs/vue3',
                    replacement: fixture('adminAccountInertia.mjs'),
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
    ({ default: Users } = await server.ssrLoadModule(
        '/resources/js/pages/Admin/Users.vue',
    ));
});
after(async () => {
    await server?.close();
});
const page = (url) => ({
    stats: {},
    filters: { status: 'all', sort: 'created_desc' },
    meta: { active_window_minutes: 5, server_time: '2026-11-26T12:00:00Z' },
    users: { data: [], current_page: 1, last_page: 1, total: 0 },
    submissions: {
        data: [
            {
                id: 1,
                message: 'Synthetic feedback',
                page_url: url,
                status: 'new',
            },
        ],
        current_page: 1,
        last_page: 1,
        total: 1,
    },
});
test('actual admin feedback component never emits a clickable executable or credential-bearing submitted URL', async () => {
    for (const url of [
        'javascript:syntheticSentinel()',
        'data:text/html,synthetic',
        'https://user:password@example.test/path',
        '//outside.example.test/path',
        'https://example.test/\npath',
    ]) {
        const html = await renderToString(createSSRApp(Users, page(url)));
        assert.doesNotMatch(
            html,
            /href="(?:javascript:|data:|https:\/\/user:password|\/\/outside)/,
        );
        assert.doesNotMatch(html, /href="https:\/\/example.test\/\npath/);
    }
});
test('actual admin feedback component preserves supported safe links', async () => {
    for (const url of ['https://example.test/game?x=1', '/nfl/predictions']) {
        const html = await renderToString(createSSRApp(Users, page(url)));
        assert.ok(html.includes(`href="${url}"`));
        assert.match(html, /rel="noopener noreferrer"/);
    }
});

test('submission navigation anchor identifies the form-submission panel', async () => {
    const html = await renderToString(createSSRApp(Users, page(null)));
    const panel = html.slice(html.indexOf('id="submissions"'));
    assert.ok(panel.indexOf('Form Submissions') < panel.indexOf('<table'));
});
