import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
export async function createHomepageTestServer() {
    const root = fileURLToPath(new URL('../../../', import.meta.url));
    return createServer({
        configFile: false,
        plugins: [
            {
                name: 'homepage-routes',
                enforce: 'pre',
                resolveId(id) {
                    if (id.endsWith('/routes'))
                        return 'virtual:homepage-routes';
                },
                load(id) {
                    if (id === 'virtual:homepage-routes')
                        return [
                            'dashboard',
                            'login',
                            'register',
                            'performance',
                            'terms',
                            'privacy',
                            'responsibleGambling',
                        ]
                            .map(
                                (name) =>
                                    `export const ${name}=()=>({url:'/${name === 'responsibleGambling' ? 'responsible-gambling' : name}'});`,
                            )
                            .join('\n');
                },
            },
            vue(),
        ],
        resolve: {
            alias: [
                {
                    find: '@inertiajs/vue3',
                    replacement:
                        root + 'tests/frontend/fixtures/homepageInertia.mjs',
                },
                { find: '@', replacement: root + 'resources/js' },
            ],
        },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
}
