import { fileURLToPath, URL } from 'node:url';
import { defineConfig, loadEnv } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const backend = env.FRONTEND_DEV_BACKEND || 'http://127.0.0.1:8000';
    return {
        plugins: [
            vue(),
            tailwindcss(),
            {
                name: 'standalone-boundary',
                generateBundle() {
                    for (const id of this.getModuleIds()) {
                        if (
                            /\/node_modules\/@inertiajs\/|\/resources\/js\/(routes|actions|wayfinder)\//.test(
                                id,
                            )
                        ) {
                            this.error(
                                `Standalone frontend cannot depend on server-generated routing or Inertia: ${id}`,
                            );
                        }
                    }
                },
            },
        ],
        resolve: {
            alias: [
                {
                    find: '@/platform/AppLayout.vue',
                    replacement: fileURLToPath(
                        new URL('./src/AppLayout.vue', import.meta.url),
                    ),
                },
                {
                    find: '@/platform',
                    replacement: fileURLToPath(
                        new URL('./src/platform/index.ts', import.meta.url),
                    ),
                },
                {
                    find: '@',
                    replacement: fileURLToPath(
                        new URL('../resources/js', import.meta.url),
                    ),
                },
            ],
        },
        server: {
            port: 5174,
            strictPort: true,
            proxy: Object.fromEntries(
                [
                    '/api',
                    '/sanctum',
                    '/login',
                    '/logout',
                    '/two-factor-challenge',
                ].map((path) => [
                    path,
                    {
                        target: backend,
                        changeOrigin: true,
                        bypass(request) {
                            if (
                                path === '/login' &&
                                request.method === 'GET' &&
                                request.headers.accept?.includes('text/html')
                            )
                                return request.url;
                        },
                    },
                ]),
            ),
        },
        build: { outDir: 'dist', emptyOutDir: true },
    };
});
