// Synthetic UI fixture only; no provider or production database access.
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwind from '@tailwindcss/vite';
import units from '../../resources/js/lib/nflUnitMetrics.json' with { type: 'json' };

const root = fileURLToPath(new URL('../../', import.meta.url));
const labels = [
    'All teams · this exact line',
    'All teams · same line & venue',
    'This team · this exact line',
    'This team · same line & venue',
    'Team + coach · same line & venue',
    'Team + starting QB · same line & venue',
    'Team + coach + QB · same line & venue',
];
const bands = {
    all: { label: 'All strengths', league: [50, 50], team: [2, 1] },
    top_5: {
        label: 'Top 5 · ranks 1–5',
        league: [8, 12],
        team: [0, 2],
    },
    top_10: {
        label: 'Top 10 · ranks 1–10',
        league: [20, 18],
        team: [1, 2],
    },
    bottom_10: {
        label: 'Bottom 10 · ranks 23–32',
        league: [24, 16],
        team: [3, 1],
    },
    bottom_5: {
        label: 'Bottom 5 · ranks 28–32',
        league: [12, 6],
        team: [2, 0],
    },
};
const side = (team, line, venue, band, unavailableReason) => ({
    team,
    line,
    venue,
    coach: null,
    qb: null,
    qb_identity_source: null,
    identity_coverage: {
        cohort_games: unavailableReason ? 0 : band.team[0] + band.team[1],
        coach_known_games: 0,
        qb_known_games: 0,
    },
    rows: labels.map((label, index) => {
        const [wins, losses] = unavailableReason
            ? [0, 0]
            : index < 2
              ? band.league
              : index < 4
                ? band.team
                : [0, 0];
        return {
            id: String(index),
            label,
            status:
                unavailableReason || index > 3 ? 'unavailable' : 'available',
            reason:
                unavailableReason ??
                (index > 3
                    ? 'Game-specific coach or starting QB is not recorded.'
                    : null),
            sample_size: wins + losses,
            unique_games: wins + losses,
            small_sample: wins + losses < 20,
            ats: { wins, losses, pushes: 0 },
            outright: { wins, losses, ties: 0 },
            ats_win_pct: wins + losses ? (100 * wins) / (wins + losses) : null,
            average_margin: null,
            average_cover_margin: null,
            from_date: unavailableReason || index > 3 ? null : '2009-09-10',
            through_date: unavailableReason || index > 3 ? null : '2025-12-28',
        };
    }),
});
const server = await createServer({
    root,
    configFile: false,
    plugins: [
        {
            name: 'historical-market-fixture',
            configureServer(vite) {
                vite.middlewares.use(
                    '/api/v2/sports/nfl/games/1722/market-history',
                    (request, response) => {
                        const query = new URL(request.url, 'http://localhost')
                            .searchParams;
                        const line = Number(query.get('home_line') ?? -5.5);
                        response.setHeader('Content-Type', 'application/json');
                        const bandKey = query.get('strength_band') ?? 'all';
                        const unit = units.find(
                            (item) =>
                                item.id ===
                                (query.get('opponent_unit') ?? 'defense'),
                        );
                        const metric = unit?.metrics.find(
                            (item) =>
                                item.id ===
                                (query.get('opponent_metric') ??
                                    unit.metrics[0].id),
                        );
                        if (
                            !Object.hasOwn(bands, bandKey) ||
                            !unit ||
                            !metric
                        ) {
                            response.statusCode = 422;
                            response.end(
                                JSON.stringify({
                                    message:
                                        'Unknown unit, metric or strength band',
                                }),
                            );
                            return;
                        }
                        const supported = [
                            'points_per_game',
                            'points_allowed_per_game',
                        ].includes(metric.id);
                        const unavailableReason = supported
                            ? null
                            : `Unavailable: this preview has no verified ${metric.label} rankings. No records inferred from scores or another unit.`;
                        const sample = bands[bandKey];
                        // Distinct synthetic offense fixtures, never labeled production history.
                        const band =
                            unit.id === 'offense'
                                ? {
                                      ...sample,
                                      league: [...sample.league].reverse(),
                                      team: [...sample.team].reverse(),
                                  }
                                : sample;
                        response.end(
                            JSON.stringify({
                                data: {
                                    unit_filter: {
                                        unit: unit.id,
                                        band: bandKey,
                                        label: `${unit.label} · ${metric.label} · ${band.label}`,
                                        metric: metric.id,
                                        status: supported
                                            ? 'available'
                                            : 'unavailable',
                                        description:
                                            unavailableReason ??
                                            'Synthetic preview counts, not real results. Real scoring ranks require all 32 teams with 3+ prior games; ties crossing the band boundary are excluded.',
                                    },
                                    since_season: Number(
                                        query.get('since') ?? 2009,
                                    ),
                                    target_season: 2026,
                                    before_date: '2026-09-18',
                                    market: {
                                        home_line: line,
                                        source: query.has('home_line')
                                            ? 'user_selected'
                                            : 'observed_pregame',
                                        bookmaker: query.has('home_line')
                                            ? null
                                            : 'Fixture sportsbook',
                                        observed_at: null,
                                    },
                                    coverage: {
                                        completed_games: 4400,
                                        games_with_verified_line: 4300,
                                        excluded_missing_or_conflicting_line: 100,
                                        through_date: '2026-01-04',
                                    },
                                    sides: {
                                        home: side(
                                            'BUF',
                                            line,
                                            'home',
                                            band,
                                            unavailableReason,
                                        ),
                                        away: side(
                                            'DET',
                                            -line,
                                            'away',
                                            band,
                                            unavailableReason,
                                        ),
                                    },
                                    limitations: [
                                        'Synthetic layout test: not real results or betting advice.',
                                        'QB records against strong units are team-result associations, not measures of individual quarterback performance. Overlapping unit trends are not independent votes.',
                                    ],
                                },
                            }),
                        );
                    },
                );
                vite.middlewares.use(
                    '/__market-history',
                    async (_request, response) => {
                        response.setHeader('Content-Type', 'text/html');
                        response.end(
                            await vite.transformIndexHtml(
                                '/__market-history',
                                `<!doctype html>
<html class="dark"><head><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body><main style="max-width:390px;margin:auto;padding:12px"><p>SYNTHETIC MOBILE QA FIXTURE</p><div id="app"></div></main>
<script type="module">import { createApp } from 'vue';
import Evidence from '/resources/js/components/game-page/NflMarketHistory.vue';
import '/resources/css/app.css'; createApp(Evidence, { gameId: 1722, enableUnitFilters: true }).mount('#app');
document.querySelector('#app details').open = true;</script></body></html>`,
                            ),
                        );
                    },
                );
            },
        },
        vue(),
        tailwind(),
    ],
    resolve: { alias: { '@': `${root}resources/js` } },
    server: {
        host: '127.0.0.1',
        port: 5193,
        strictPort: true,
        hmr: false,
        ws: false,
        watch: null,
    },
});
await server.listen();
console.log('http://127.0.0.1:5193/__market-history');
