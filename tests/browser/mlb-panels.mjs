// Synthetic fixtures only; no production API requests or dependency changes.
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwind from '@tailwindcss/vite';
const { chromium } = await import(
    process.env.PLAYWRIGHT_MODULE_PATH || 'playwright'
);
const root = fileURLToPath(new URL('../../', import.meta.url));
const server = await createServer({
    root,
    configFile: false,
    plugins: [
        {
            name: 'mlb-panels-fixture',
            configureServer(vite) {
                vite.middlewares.use('/__mlb-panels', async (_, response) => {
                    response.setHeader('Content-Type', 'text/html');
                    response.end(
                        await vite.transformIndexHtml(
                            '/__mlb-panels',
                            `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><main class="mx-auto max-w-6xl p-4"><div id="app"></div></main><script type="module">
import {createApp,h,ref} from 'vue';
import Drawer from '/resources/js/components/mlb/MlbMatchupDetailDrawer.vue';
import Board from '/resources/js/components/mlb/MlbSignalsBoard.vue';
import '/resources/css/app.css';
const candidates = [{id:1,market_type:'moneyline',label:'Home moneyline',is_public:false,is_tracking_only:true,reason_codes:['home_field_support'],risk_flags:[],model_probability:0.6},{id:2,market_type:'total',label:'Under 8.5',is_public:false,is_tracking_only:true,reason_codes:[],risk_flags:['missing_pitcher'],model_probability:0.4}];
const prediction = {status:'STATUS_SCHEDULED',game:{status:'STATUS_SCHEDULED',game_date:'2026-11-04',game_time:'18:00:00',away_team:{abbreviation:'AWY'},home_team:{abbreviation:'HOM'}}};
const payload = {season:2026,as_of_date:'2026-11-04',backend_review:{model:'fixture',strengths:['home_edge'],watch_items:[]},recommended_bets:[],world_series:[],moneyline:[],run_line:[],totals:[],ballpark:[],live:[],streaks:[]};
createApp({setup(){const open=ref(false),candidate=ref(candidates[0]);return()=>h('div',[
h('button',{id:'open-drawer',type:'button',onClick:()=>open.value=true},'Open matchup'),
h(Board,{payload}),
h(Drawer,{open:open.value,'onUpdate:open':v=>open.value=v,prediction,candidate:candidate.value,candidates,onSelectCandidate:v=>candidate.value=v})
]);}}).mount('#app');
</script></body></html>`,
                        ),
                    );
                });
            },
        },
        vue(),
        tailwind(),
    ],
    resolve: { alias: { '@': `${root}resources/js` } },
    server: { host: '127.0.0.1', port: 0, hmr: false, ws: false, watch: null },
});
let browser;
try {
    await server.listen();
    console.log('Fixture server ready');
    browser = await chromium.launch({
        headless: true,
        channel: process.env.PLAYWRIGHT_CHANNEL,
    });
    for (const width of [390, 1280]) {
        for (const theme of ['light', 'dark']) {
            const page = await browser.newPage({
                viewport: { width, height: 900 },
            });
            page.setDefaultTimeout(10000);
            console.log(`Starting ${width}px ${theme}`);
            const errors = [];
            page.on('pageerror', (error) => errors.push(error.message));
            await page.route('**/*', (route) =>
                new URL(route.request().url()).hostname === '127.0.0.1'
                    ? route.continue()
                    : route.abort(),
            );
            await page.goto(
                `http://127.0.0.1:${server.httpServer.address().port}/__mlb-panels`,
                { waitUntil: 'domcontentloaded', timeout: 15000 },
            );
            console.log('Fixture loaded');
            await page.evaluate(
                (dark) =>
                    document.documentElement.classList.toggle('dark', dark),
                theme === 'dark',
            );
            const summary = page.getByText('Model controls and diagnostics', {
                exact: true,
            });
            await summary.click();
            assert.equal(
                await summary.evaluate((el) => el.closest('details').open),
                true,
            );
            const opener = page.getByRole('button', { name: 'Open matchup' });
            await opener.focus();
            await page.keyboard.press('Enter');
            const dialog = page.getByRole('dialog');
            await dialog.waitFor();
            await dialog.getByRole('button', { name: /Under 8.5/ }).click();
            assert.equal(
                await dialog.getByText('Under 8.5', { exact: true }).count(),
                1,
            );
            assert.match(await dialog.innerText(), /40.0%/);
            assert.match(await dialog.innerText(), /model review only/);
            assert.ok(
                await dialog.evaluate(
                    (el) => el.scrollWidth <= el.clientWidth + 1,
                ),
            );
            await page.keyboard.press('Escape');
            await dialog.waitFor({ state: 'hidden' });
            await opener.click();
            await dialog.waitFor();
            await dialog
                .getByRole('button', { name: 'Close', exact: true })
                .click();
            await dialog.waitFor({ state: 'hidden' });
            assert.deepEqual(errors, []);
            console.log(
                `MLB drawer markets, close controls and diagnostics: ${width}px ${theme} passed`,
            );
            await page.close();
        }
    }
} catch (error) {
    console.error(error);
    throw error;
} finally {
    await browser?.close();
    await server.close();
}
