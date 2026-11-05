import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
let server, page;
before(async () => {
    server = await createServer({
        configFile: false,
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
    page = await server.ssrLoadModule(
        '/resources/js/lib/playerPagePresentation.ts',
    );
});
after(async () => {
    await server?.close();
});
test('default summary and log columns keep existing zero, missing and weighted-percentage displays', () => {
    const rows = [
        { points: 0, field_goals_made: 1, field_goals_attempted: 2 },
        { points: 10, field_goals_made: 1, field_goals_attempted: 8 },
    ];
    assert.equal(
        page.defaultSummaryCards.find((x) => x.label === 'PPG').value(rows),
        '5.0',
    );
    assert.equal(
        page.defaultSummaryCards.find((x) => x.label === 'FG%').value(rows),
        '20.0',
    );
    assert.equal(
        page.defaultGameLogColumns
            .find((x) => x.label === 'PTS')
            .value({ points: 0 }),
        '-',
    );
    assert.equal(page.formatOdds(110), '+110');
    assert.equal(page.formatDate(null), '-');
    assert.deepEqual(
        page.getPlayerOpponent(
            { game: { home_team_id: 1, away_team: { abbreviation: 'AWY' } } },
            { team_id: 1 },
        ),
        { label: 'vs', name: 'AWY' },
    );
});
