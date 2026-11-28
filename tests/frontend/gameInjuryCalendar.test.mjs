import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
let server, Card, availabilityFreshness;
const originalTimezone = process.env.TZ;
before(async () => {
    process.env.TZ = 'America/Chicago';
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
    ({ default: Card } = await server.ssrLoadModule(
        '/resources/js/components/game-page/InjuryReportCard.vue',
    ));
    ({ availabilityFreshness } = await server.ssrLoadModule(
        '/resources/js/lib/dataFreshness.ts',
    ));
});
after(async () => {
    await server?.close();
    if (originalTimezone === undefined) delete process.env.TZ;
    else process.env.TZ = originalTimezone;
});
const listing = {
    id: 1,
    player_id: 1,
    player_name: 'Synthetic Player',
    status: 'Out',
    return_date: '2026-11-27',
};
const html = async (entry) =>
    (
        await renderToString(
            createSSRApp(Card, {
                awayInjuries: [entry],
                homeInjuries: [],
                storedListings: true,
            }),
        )
    )
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ');
test('clinical return dates retain their supplied calendar day in a western browser timezone', async () => {
    assert.match(await html(listing), /Return Nov 27, 2026/);
});
test('clinical listing dates retain their supplied calendar day when return is unavailable', async () => {
    assert.match(
        await html({
            ...listing,
            return_date: null,
            injury_date: '2026-11-27',
        }),
        /Listed Nov 27, 2026/,
    );
});
test('unqualified source clocks are not converted into an oldest observation instant', () => {
    const text = availabilityFreshness([
        { source_updated_at: '2026-11-26 22:00:00' },
    ]);
    assert.doesNotMatch(text, /Oldest supplied update/);
    assert.match(text, /unverified|unavailable/i);
});
test('an empty injury list continues to distinguish missing report coverage', () => {
    assert.match(
        availabilityFreshness([]),
        /empty list does not verify a fresh report/,
    );
});

test('raw legacy clock text retains coverage without becoming a verified instant', () => {
    assert.match(
        availabilityFreshness([
            {
                source_updated_at: null,
                source_updated_at_raw: '2026-11-26 22:00:00',
            },
            { source_updated_at: null, source_updated_at_raw: null },
        ]),
        /1\/2 listings.*unverified/,
    );
});
