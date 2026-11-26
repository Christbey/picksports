// Manual native Safari QA using the real Vue component and local CSS.
// node tests/browser/safari-reader-app.mjs; open the printed /before and /after URLs.
// Fixtures are synthetic. No production API, credentials or third-party scripts.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

const root = fileURLToPath(new URL('../../', import.meta.url));
const shell = readFileSync(root + 'resources/views/app.blade.php', 'utf8');
const wrapper = shell.match(/<div id="app-reader-comment"[^>]*>/)?.[0];
if (!wrapper) throw new Error('Missing production Reader exclusion wrapper');
const pages = new Map();
const server = await createServer({
    root,
    configFile: false,
    plugins: [
        {
            name: 'reader-control',
            configureServer(vite) {
                vite.middlewares.use((request, response, next) => {
                    const html = pages.get(request.url?.split('?')[0]);
                    if (!html) return next();
                    response.setHeader('Content-Type', 'text/html');
                    response.end(html);
                });
            },
        },
        vue(),
    ],
    resolve: { alias: { '@': root + 'resources/js' } },
    optimizeDeps: { noDiscovery: true, include: [] },
    server: {
        host: '127.0.0.1',
        port: 5198,
        strictPort: true,
        hmr: false,
        ws: false,
        watch: null,
    },
});
const metrics = Object.fromEntries(
    [
        'points_for',
        'points_against',
        'margin',
        'yards_per_play',
        'turnovers',
        'third_down_pct',
        'red_zone_pct',
        'yards_allowed',
    ].map((key, i) => [
        key,
        { value: 20 + i, sample_size: 5, rank: i + 1, ranked_teams: 32 },
    ]),
);
const evidence = {
    sample_size: 5,
    record: { wins: 5, losses: 0, ties: 0 },
    seasons: [2026],
    from_date: '2026-10-18',
    through_date: '2026-11-22',
    latest_source_update: '2026-11-23T08:16:07-06:00',
    metrics,
};
const profile = { windows: { recent_5: { evidence } }, changes: [] };
const manifest = JSON.parse(
    readFileSync(root + 'public/build/manifest.json', 'utf8'),
);
const css = '/build/' + manifest['resources/js/app.ts'].css[0];
await server.listen();
for (const mode of ['before', 'after']) {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/NflTeamEvidencePanel.vue',
    );
    const html = await renderToString(
        createSSRApp(component, {
            workspace: true,
            away: profile,
            home: profile,
            awayLabel: 'CHI',
            homeLabel: 'DET',
            window: 'recent_5',
        }),
    );
    const rendered = mode === 'after' ? `${wrapper}${html}</div>` : html;
    pages.set(
        '/' + mode,
        `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Safari Reader ${mode} — actual component</title><link rel="stylesheet" href="${css}"></head><body class="font-sans antialiased"><nav class="p-4"><a href="/before">Before</a> · <a href="/after">After</a></nav><main class="mx-auto max-w-6xl p-4"><h1>Game comparison — synthetic QA</h1>${rendered}</main></body></html>`,
    );
}
console.log(
    'Native Safari QA: http://127.0.0.1:5198/before and http://127.0.0.1:5198/after',
);
