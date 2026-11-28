import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { computed, createSSRApp, h, nextTick, reactive, ref, unref } from 'vue';
import { renderToString } from '@vue/server-renderer';
import vue from '@vitejs/plugin-vue';
import { createServer } from 'vite';

let server;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'nav-user-contract-fixtures',
                enforce: 'pre',
                resolveId(id) {
                    if (id === '@inertiajs/vue3' || id === '/@nav-page')
                        return '\0nav-page';
                    if (
                        id === '@/components/ui/dropdown-menu' ||
                        id === '@/components/UserInfo.vue' ||
                        id === './UserMenuContent.vue'
                    )
                        return '\0nav-child';
                },
                load(id) {
                    if (id === '\0nav-page')
                        return 'export const usePage = () => globalThis.__navIdentityPage;';
                    if (id === '\0nav-child')
                        return 'export default {}; export const DropdownMenu={},DropdownMenuContent={},DropdownMenuTrigger={};';
                },
            },
            vue(),
        ],
        resolve: {
            alias: {
                '@inertiajs/vue3': '/@nav-page',
                '@': fileURLToPath(
                    new URL('../../resources/js', import.meta.url),
                ),
            },
        },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
});
after(async () => {
    delete globalThis.__navIdentityPage;
    await server?.close();
});

test('persisted navigation observes replacement account identity and logout', async () => {
    const page = reactive({
        props: { auth: { user: { id: 1, name: 'First account' } } },
    });
    globalThis.__navIdentityPage = page;
    const component = (
        await server.ssrLoadModule('/resources/js/components/NavUser.vue')
    ).default;
    const { provideSidebarContext } = await server.ssrLoadModule(
        '/resources/js/components/ui/sidebar/utils.ts',
    );
    let bindings;
    await renderToString(
        createSSRApp({
            setup() {
                provideSidebarContext({
                    isMobile: ref(false),
                    state: computed(() => 'expanded'),
                });
                return () =>
                    h({
                        setup() {
                            bindings = component.setup({}, { expose() {} });
                            return () => h('div');
                        },
                    });
            },
        }),
    );
    assert.equal(unref(bindings.user).id, 1);
    page.props.auth.user = { id: 2, name: 'Replacement account' };
    await nextTick();
    assert.equal(unref(bindings.user).id, 2);
    page.props.auth.user = null;
    await nextTick();
    assert.equal(unref(bindings.user), null);
});
