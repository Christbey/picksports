import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { before, after, test } from 'node:test';
import { createRenderer, h, nextTick, ssrContextKey } from 'vue';
import { createServer, transformWithEsbuild } from 'vite';
import { parse, compileScript } from '@vue/compiler-sfc';
import { readFileSync } from 'node:fs';
import vue from '@vitejs/plugin-vue';
const root =
    process.env.REVIEW_ROOT ||
    fileURLToPath(new URL('../../', import.meta.url)).replace(/\/$/, '');
let server;
before(async () => {
    server = await createServer({
        root,
        configFile: false,
        plugins: [
            {
                name: 'actual-boundary-client-compile',
                enforce: 'pre',
                async load(id) {
                    if (id.startsWith('/@boundary/')) {
                        const path = id.slice('/@boundary/'.length);
                        const file =
                            root + '/resources/js/components/' + path + '.vue';
                        const { descriptor } = parse(
                            readFileSync(file, 'utf8'),
                            { filename: file },
                        );
                        const code = compileScript(descriptor, {
                            id: path,
                            inlineTemplate: true,
                        }).content;
                        return (
                            await transformWithEsbuild(code, file + '.ts', {
                                loader: 'ts',
                            })
                        ).code;
                    }
                    if (id.endsWith('/components/ui/alert/index.ts'))
                        return `import { h } from 'vue'; export const Alert = { setup: (_, {slots}) => () => h('div', slots.default?.()) }; export const AlertTitle = Alert; export const AlertDescription = Alert;`;
                },
            },
            vue(),
        ],
        resolve: { alias: { '@': root + '/resources/js' } },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
});
after(() => server?.close());
const node = (text = '') => ({ text, children: [], parent: null });
const renderer = createRenderer({
    createElement: () => node(),
    createText: (text) => node(text),
    createComment: () => node(),
    insert(child, parent, anchor) {
        if (child.parent) this.remove(child);
        const i = anchor ? parent.children.indexOf(anchor) : -1;
        parent.children.splice(i < 0 ? parent.children.length : i, 0, child);
        child.parent = parent;
    },
    remove(child) {
        if (child.parent) {
            const i = child.parent.children.indexOf(child);
            if (i >= 0) child.parent.children.splice(i, 1);
            child.parent = null;
        }
    },
    setText: (n, text) => {
        n.text = text;
    },
    setElementText: (n, text) => {
        n.text = text;
        n.children = [];
    },
    parentNode: (n) => n.parent,
    nextSibling: (n) => {
        const list = n.parent?.children || [];
        return list[list.indexOf(n) + 1] || null;
    },
    patchProp() {},
});
const content = (n) => n.text + n.children.map(content).join(' ');
for (const [path, title] of [
    ['RenderErrorBoundary', 'Rendering Error'],
    ['game-page/GamePageErrorBoundary', 'Page Rendering Error'],
]) {
    for (const message of ['', 'Synthetic rendering failure']) {
        test(`${path} renders a fallback for ${message ? 'ordinary' : 'empty'} Error.message`, async () => {
            const { default: Boundary } = await server.ssrLoadModule(
                `/@boundary/${path}`,
            );
            const target = node();
            const app = renderer.createApp({
                render: () =>
                    h(Boundary, null, {
                        default: () =>
                            h({
                                render() {
                                    throw new Error(message);
                                },
                            }),
                    }),
            });
            app.provide(ssrContextKey, {});
            app.mount(target);
            try {
                await nextTick();
                assert.match(content(target), new RegExp(title));
                assert.match(
                    content(target),
                    /Synthetic rendering failure|unexpected rendering error/,
                );
            } finally {
                app.unmount();
            }
        });
    }
}
