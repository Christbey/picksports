import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
let server, tabs, Search, useDialogFocusReturn;
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
    tabs = await server.ssrLoadModule(
        '/resources/js/components/ui/tabs/index.ts',
    );
    ({ default: Search } = await server.ssrLoadModule(
        '/resources/js/components/UserSearchInput.vue',
    ));
    ({ useDialogFocusReturn } = await server.ssrLoadModule(
        '/resources/js/composables/useDialogFocusReturn.ts',
    ));
});
after(async () => server?.close());
test('shared tabs render tab semantics, disabled state, and the controlled panel only', async () => {
    const { Tabs, TabsList, TabsTrigger, TabsContent } = tabs;
    const html = await renderToString(
        createSSRApp({
            render: () =>
                h(Tabs, { modelValue: 'two', defaultValue: 'one' }, () => [
                    h(TabsList, { 'aria-label': 'Sections' }, () => [
                        h(TabsTrigger, { value: 'one' }, () => 'One'),
                        h(
                            TabsTrigger,
                            { value: 'disabled', disabled: true },
                            () => 'Disabled',
                        ),
                        h(TabsTrigger, { value: 'two' }, () => 'Two'),
                    ]),
                    h(TabsContent, { value: 'one' }, () => 'HIDDEN_ONE_BODY'),
                    h(TabsContent, { value: 'two' }, () => 'VISIBLE_TWO_BODY'),
                ]),
        }),
    );
    assert.match(html, /role="tablist"/);
    assert.equal((html.match(/role="tab"/g) || []).length, 3);
    assert.match(html, /aria-selected="true"/);
    assert.match(html, /disabled/);
    assert.match(html, /role="tabpanel"/);
    assert.match(html, /aria-labelledby="[^"]+"/);
    assert.match(html, /VISIBLE_TWO_BODY/);
    assert.doesNotMatch(html, /HIDDEN_ONE_BODY/);
});
test('search fields expose labels, listbox options, unique IDs, and a live status without changing text', async () => {
    const suggestions = [{ id: 1, name: 'Maria', email: 'maria@example.com' }];
    const html = await renderToString(
        createSSRApp({
            render: () =>
                h('div', [
                    h(Search, {
                        modelValue: 'Ma',
                        suggestions,
                        label: 'User Search',
                        id: 'named-search',
                    }),
                    h(Search, {
                        modelValue: 'Ma',
                        suggestions,
                        ariaLabel: 'Group users',
                    }),
                ]),
        }),
    );
    assert.equal((html.match(/role="combobox"/g) || []).length, 2);
    assert.equal((html.match(/role="listbox"/g) || []).length, 2);
    assert.equal((html.match(/role="option"/g) || []).length, 2);
    assert.match(html, /for="named-search"/);
    assert.match(html, /aria-label="Group users"/);
    assert.match(html, /aria-expanded="true"/);
    assert.match(html, /role="status"/);
    assert.match(html, /tabindex="-1"/);
    assert.match(html, /Maria/);
    assert.match(html, /maria@example.com/);
    const ids = [...html.matchAll(/\bid="([^"]+)"/g)].map((m) => m[1]);
    assert.equal(new Set(ids).size, ids.length);
});
test('empty search fields remain collapsed without a phantom active descendant', async () => {
    const html = await renderToString(
        createSSRApp(Search, {
            modelValue: '',
            suggestions: [],
            ariaLabel: 'Find user',
        }),
    );
    assert.match(html, /aria-expanded="false"/);
    assert.doesNotMatch(html, /role="listbox"|aria-activedescendant=/);
});
function focusEnvironment(t) {
    const old = {
        document: globalThis.document,
        HTMLElement: globalThis.HTMLElement,
    };
    class Element {
        isConnected = true;
        calls = [];
        focus(options) {
            this.calls.push(options);
        }
    }
    globalThis.HTMLElement = Element;
    const opener = new Element();
    globalThis.document = { body: new Element(), activeElement: opener };
    t.after(() => Object.assign(globalThis, old));
    return { Element, opener };
}
test('controlled drawers return focus to an external opener once and forward events once', (t) => {
    const { opener } = focusEnvironment(t);
    const events = [];
    const focus = useDialogFocusReturn({
        open: (e) => events.push(['open', e]),
        close: (e) => events.push(['close', e]),
    });
    const open = new Event('open', { cancelable: true }),
        close = new Event('close', { cancelable: true });
    focus.onOpenAutoFocus(open);
    focus.onCloseAutoFocus(close);
    assert.equal(close.defaultPrevented, true);
    assert.deepEqual(opener.calls, [{ preventScroll: true }]);
    assert.deepEqual(
        events.map((e) => e[0]),
        ['open', 'close'],
    );
    focus.onCloseAutoFocus(new Event('close', { cancelable: true }));
    assert.equal(opener.calls.length, 1);
});
test('explicit close focus handlers and removed openers are respected', (t) => {
    const { opener } = focusEnvironment(t);
    let focus = useDialogFocusReturn({
        open() {},
        close: (e) => e.preventDefault(),
    });
    focus.onOpenAutoFocus(new Event('open'));
    focus.onCloseAutoFocus(new Event('close', { cancelable: true }));
    assert.equal(opener.calls.length, 0);
    focus = useDialogFocusReturn({ open() {}, close() {} });
    focus.onOpenAutoFocus(new Event('open'));
    opener.isConnected = false;
    const close = new Event('close', { cancelable: true });
    focus.onCloseAutoFocus(close);
    assert.equal(close.defaultPrevented, false);
    assert.equal(opener.calls.length, 0);
});
