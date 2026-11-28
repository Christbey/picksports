import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { harness, queue, flush } from './fixtures/boardCleanupHarness.mjs';
let env;
before(async () => (env = await harness()));
after(() => env.server.close());
const config = (extra = {}) => ({
    sport: 'nfl',
    sportLabel: 'NFL',
    predictionsHref: '/nfl/predictions',
    headTitle: (t) => t.name,
    teamHref: (id) => '/nfl/teams/' + id,
    metricTiles: [],
    showRoster: true,
    ...extra,
});
const team = (id) => ({ data: { id, name: 'Team ' + id } }),
    metrics = (season = 2026) => ({ data: { season } });
async function mount(t, extra = {}) {
    const show = queue(),
        metric = queue(),
        games = queue(),
        roster = queue(),
        stats = queue(),
        ranks = queue(),
        statRanks = queue(),
        trends = queue(),
        depth = queue();
    const api = {
        teams: {
            show: show.run,
            metrics: metric.run,
            games: games.run,
            players: roster.run,
            statSeasonAverages: stats.run,
            trends: trends.run,
            depthCharts: depth.run,
        },
        metrics: { teams: ranks.run },
        stats: { teamSeasonAverages: statRanks.run },
    };
    return {
        ...(await env.mount(t, 'useSportTeamData', api, {
            teamId: 1,
            config: config(extra),
        })),
        show,
        metric,
        games,
        roster,
        stats,
        ranks,
        statRanks,
        trends,
        depth,
    };
}
async function ready(x) {
    x.show.calls[0].resolve(team(1));
    x.metric.calls[0].resolve(metrics());
    await flush();
    x.games.calls[0].resolve({ data: [] });
    x.roster.calls[0]?.resolve({ data: [{ id: 1, name: 'Player' }] });
    await flush();
}
test('team core failure is recoverable, null is not an empty team, and a retry uses the same scoped contract', async (t) => {
    const x = await mount(t);
    x.show.calls[0].resolve(null);
    x.metric.calls[0].resolve(metrics());
    await flush();
    assert.match(x.state.error.value, /could not be loaded/);
    assert.equal(x.state.loading.value, false);
    assert.equal(x.state.teamData.value, null);
    const retry = x.state.loadTeam();
    x.show.calls[1].resolve(team(1));
    x.metric.calls[1].resolve(metrics());
    await flush();
    x.games.calls[0].resolve({ data: [] });
    x.roster.calls[0].resolve({ data: [] });
    await retry;
    assert.equal(x.state.error.value, null);
    assert.equal(x.state.teamData.value.id, 1);
    assert.deepEqual(x.games.calls[0].args[2].query, {
        per_page: 200,
        season: 2026,
    });
});
test('team metrics failure preserves the team and successful details while exposing its own recovery', async (t) => {
    const x = await mount(t);
    x.show.calls[0].resolve(team(1));
    x.metric.calls[0].reject(Error('Metrics offline'));
    await flush();
    assert.equal(x.state.teamData.value.id, 1);
    assert.equal(x.state.error.value, null);
    assert.equal(x.state.sectionErrors.value.metrics, 'Metrics offline');
    assert.deepEqual(x.games.calls[0].args[2].query, { per_page: 200 });
    x.games.calls[0].resolve({ data: [] });
    x.roster.calls[0].resolve({ data: [{ id: 3 }] });
    await flush();
    assert.equal(x.state.rosterPlayers.value[0].id, 3);
});
test('team supplemental failure retries just that section, rejects null, and guards duplicate retry clicks', async (t) => {
    const x = await mount(t);
    x.show.calls[0].resolve(team(1));
    x.metric.calls[0].resolve(metrics());
    await flush();
    x.games.calls[0].resolve({ data: [] });
    x.roster.calls[0].resolve(null);
    await flush();
    assert.match(x.state.sectionErrors.value.roster, /unavailable/);
    const pending = x.state.retrySection('roster');
    await x.state.retrySection('roster');
    assert.equal(x.roster.calls.length, 2);
    assert.equal(x.show.calls.length, 1);
    assert.equal(x.state.rosterLoading.value, true);
    x.roster.calls[1].resolve({ data: [] });
    await pending;
    assert.equal(x.state.sectionErrors.value.roster, undefined);
    assert.equal(x.state.rosterLoading.value, false);
    assert.deepEqual(x.state.rosterPlayers.value, []);
});
test('team identity changes abort core reads and ignore late successes even when transport ignores cancellation', async (t) => {
    const x = await mount(t);
    x.props.teamId = 2;
    assert.equal(x.show.calls[0].args[2].init.signal.aborted, true);
    x.show.calls[0].resolve(team(1));
    x.metric.calls[0].resolve(metrics());
    await flush();
    assert.equal(x.state.teamData.value, null);
    assert.equal(x.state.loading.value, true);
    assert.equal(x.games.calls.length, 0);
    x.show.calls[1].resolve(team(2));
    x.metric.calls[1].resolve(metrics());
    await flush();
    assert.equal(x.state.teamData.value.id, 2);
    x.games.calls[0].resolve({ data: [] });
    x.roster.calls[0].resolve({ data: [] });
});
test('team switching clears old details and protects new loading/errors from late supplemental completion', async (t) => {
    const x = await mount(t);
    await ready(x);
    const old = x.state.retrySection('roster');
    x.props.teamId = 2;
    assert.equal(x.roster.calls[1].args[2].init.signal.aborted, true);
    assert.deepEqual(x.state.rosterPlayers.value, []);
    x.roster.calls[1].reject(Error('Old error'));
    await old;
    assert.equal(x.state.sectionErrors.value.roster, undefined);
    assert.equal(x.state.loading.value, true);
    x.show.calls[1].resolve(team(2));
    x.metric.calls[1].resolve(metrics());
    await flush();
    x.games.calls[1].resolve({ data: [] });
    x.roster.calls[2].resolve({ data: [{ id: 2 }] });
    await flush();
    assert.equal(x.state.rosterPlayers.value[0].id, 2);
});
test('team disposal aborts pending supplemental reads and suppresses late state writes and further retries', async (t) => {
    const x = await mount(t);
    x.show.calls[0].resolve(team(1));
    x.metric.calls[0].resolve(metrics());
    await flush();
    x.app.unmount();
    assert.equal(x.games.calls[0].args[2].init.signal.aborted, true);
    x.games.calls[0].reject(Error('Late'));
    x.roster.calls[0].resolve({ data: [{ id: 9 }] });
    await flush();
    assert.deepEqual(x.state.rosterPlayers.value, []);
    assert.equal(x.state.sectionErrors.value.games, undefined);
    await x.state.loadTeam();
    await x.state.retrySection('roster');
    assert.equal(x.show.calls.length, 1);
});
test('team disposal during core loading does not start supplemental requests', async (t) => {
    const x = await mount(t);
    x.app.unmount();
    x.show.calls[0].resolve(team(1));
    x.metric.calls[0].resolve(metrics());
    await flush();
    assert.equal(x.games.calls.length, 0);
    assert.equal(x.state.teamData.value, null);
});
test('team refresh failure retains existing team data and valid empty metrics do not become a request error', async (t) => {
    const x = await mount(t);
    await ready(x);
    const refresh = x.state.loadTeam();
    x.show.calls[1].reject(Error('Unavailable'));
    x.metric.calls[1].resolve(metrics());
    await refresh;
    assert.equal(x.state.teamData.value.id, 1);
    assert.equal(x.state.rosterPlayers.value[0].id, 1);
    assert.equal(x.state.error.value, 'Unavailable');
    const retry = x.state.loadTeam();
    x.show.calls[2].resolve(team(1));
    x.metric.calls[2].resolve({ data: null });
    await flush();
    assert.equal(x.state.sectionErrors.value.metrics, undefined);
    x.games.calls[1].resolve({ data: [] });
    x.roster.calls[1].resolve({ data: [] });
    await retry;
    assert.equal(x.state.teamMetrics.value, null);
});
test('team schedule, record and rankings preserve metric-season scoping, zero scores, ties and numeric strings', async (t) => {
    const x = await mount(t, {
        showPowerRanking: true,
        metricRankingKeys: [{ key: 'net_rating' }],
        statRankingKeys: [{ key: 'points' }],
        seasonStatTiles: [],
        showTrends: true,
        trendsGames: 12,
        showDepthCharts: true,
    });
    x.show.calls[0].resolve(team(1));
    x.metric.calls[0].resolve(metrics('2026'));
    await flush();
    assert.deepEqual(x.ranks.calls[0].args[1].query, {
        season: 2026,
        per_page: 500,
    });
    assert.deepEqual(x.depth.calls[0].args[2].query, { season: 2026 });
    assert.deepEqual(x.trends.calls[0].args[2].query, { games: 12 });
    x.games.calls[0].resolve({
        data: [
            {
                id: 1,
                season: 2026,
                status: 'STATUS_FINAL',
                home_team_id: 1,
                away_team_id: 2,
                home_score: 0,
                away_score: 0,
                game_date: '2026-01-01',
            },
            {
                id: 2,
                season: 2025,
                status: 'STATUS_FINAL',
                home_team_id: 1,
                away_team_id: 2,
                home_score: 10,
                away_score: 0,
                game_date: '2025-01-01',
            },
        ],
    });
    x.ranks.calls[0].resolve({
        data: [
            { team_id: '1', season: '2026', net_rating: 0 },
            { team_id: 2, season: 2026, net_rating: -1 },
            { team_id: 3, season: 2025, net_rating: 99 },
        ],
    });
    x.statRanks.calls[0].resolve({
        data: [
            { team_id: 1, points: 0 },
            { team_id: 2, points: 1 },
        ],
    });
    x.stats.calls[0].resolve({ data: { points: 0 } });
    x.trends.calls[0].resolve({
        data: {
            trends: { scoring: ['stored'] },
            locked_trends: { advanced: 'locked' },
        },
    });
    x.depth.calls[0].resolve({ data: null });
    x.roster.calls[0].resolve({ data: [] });
    await flush();
    assert.deepEqual(x.state.record.value, {
        wins: 0,
        losses: 0,
        ties: 1,
        games: 1,
    });
    assert.equal(x.state.recentGames.value.length, 1);
    assert.deepEqual(x.state.powerRanking.value, { rank: 1, total_teams: 2 });
    assert.equal(x.state.metricRankings.value.net_rating, 1);
    assert.equal(x.state.statRankings.value.points, 2);
    assert.equal(x.state.seasonStats.value.points, 0);
    assert.deepEqual(x.state.allTrendCategories.value, ['advanced', 'scoring']);
});
test('team configuration changes reload the correct sport and optional sections remain opt-in', async (t) => {
    const x = await mount(t, { showRoster: false });
    x.props.config = config({ sport: 'cbb', showRoster: false });
    assert.equal(x.show.calls[1].args[0], 'cbb');
    x.show.calls[1].resolve(team(1));
    x.metric.calls[1].resolve(metrics());
    x.show.calls[0].resolve(team(1));
    x.metric.calls[0].resolve(metrics());
    await flush();
    assert.equal(x.roster.calls.length, 0);
    assert.equal(x.depth.calls.length, 0);
    x.games.calls[0].resolve({ data: [] });
});
test('team invalid identity ends loading with recovery and makes no requests', async (t) => {
    const x = await mount(t);
    x.props.teamId = 0;
    await flush();
    assert.equal(x.state.loading.value, false);
    assert.match(x.state.error.value, /could not be found/);
    assert.equal(x.show.calls.length, 1);
    x.show.calls[0].resolve(team(1));
    x.metric.calls[0].resolve(metrics());
});
test('team metrics retry refreshes dependent season context without mixing prior-season games', async (t) => {
    const x = await mount(t);
    await ready(x);
    const retry = x.state.retrySection('metrics');
    x.show.calls[1].resolve(team(1));
    x.metric.calls[1].resolve(metrics(2025));
    await flush();
    assert.deepEqual(x.games.calls[1].args[2].query, {
        per_page: 200,
        season: 2025,
    });
    assert.deepEqual(x.state.rosterPlayers.value, []);
    x.games.calls[1].resolve({ data: [] });
    x.roster.calls[1].resolve({ data: [{ id: 2 }] });
    await retry;
    assert.equal(x.state.teamMetrics.value.season, 2025);
    assert.equal(x.state.rosterPlayers.value[0].id, 2);
});
test('team invalid season values keep the original unscoped query semantics', async (t) => {
    for (const season of [' ', true, 'not a season']) {
        const x = await mount(t);
        x.show.calls[0].resolve(team(1));
        x.metric.calls[0].resolve(metrics(season));
        await flush();
        assert.deepEqual(x.games.calls[0].args[2].query, { per_page: 200 });
        x.games.calls[0].resolve({ data: [] });
        x.roster.calls[0].resolve({ data: [] });
    }
});
test('team core failure does not wait for a stalled optional metrics read and cancels it', async (t) => {
    const x = await mount(t);
    x.show.calls[0].reject(Error('Team unavailable'));
    await flush();
    assert.equal(x.state.error.value, 'Team unavailable');
    assert.equal(x.state.loading.value, false);
    assert.equal(x.metric.calls[0].args[2].init.signal.aborted, true);
    x.metric.calls[0].reject(Error('Late metrics failure'));
    await flush();
    assert.equal(x.state.error.value, 'Team unavailable');
});

test('team power ranking remains unavailable when the actual metric has no observed net rating', async (t) => {
    const x = await mount(t, { sport: 'mlb', showPowerRanking: true });
    x.show.calls[0].resolve(team(1));
    x.metric.calls[0].resolve(metrics());
    await flush();
    x.games.calls[0].resolve({ data: [] });
    x.roster.calls[0].resolve({ data: [] });
    x.ranks.calls[0].resolve({
        data: [
            { team_id: 1, season: 2026, offensive_rating: 110 },
            { team_id: 2, season: 2026, offensive_rating: 90 },
        ],
    });
    await flush();
    assert.equal(x.state.powerRanking.value, null);
});

test('team stat rankings exclude unknown values while preserving a genuine observed zero', async (t) => {
    const x = await mount(t, {
        statRankingKeys: [{ key: 'turnovers', descending: false }],
    });
    x.show.calls[0].resolve(team(1));
    x.metric.calls[0].resolve(metrics());
    await flush();
    x.games.calls[0].resolve({ data: [] });
    x.roster.calls[0].resolve({ data: [] });
    x.statRanks.calls[0].resolve({
        data: [
            { team_id: 2, turnovers: null },
            { team_id: 1, turnovers: 0 },
            { team_id: 3, turnovers: 2 },
        ],
    });
    await flush();
    assert.equal(x.state.statRankings.value.turnovers, 1);
});

test('team calendar labels preserve an actual date without a browser timezone shift', async (t) => {
    const original = process.env.TZ;
    process.env.TZ = 'America/Chicago';
    try {
        const x = await mount(t);
        assert.equal(x.state.formatDate('2026-10-10'), 'Oct 10');
        assert.equal(x.state.formatDate('2026-02-30'), '-');
        assert.equal(x.state.formatDate('2026-10-10Tgarbage'), '-');
        assert.equal(x.state.formatDate('2026-10-10T00:00:00-05:00'), 'Oct 10');
        assert.equal(x.state.formatDate(null), '-');
    } finally {
        if (original === undefined) delete process.env.TZ;
        else process.env.TZ = original;
    }
});

test('CBB upcoming schedule keeps a later-today observed kickoff and excludes elapsed or terminal games', async (t) => {
    t.mock.timers.enable({
        apis: ['Date'],
        now: new Date('2026-10-10T18:00:00Z'),
    });
    const x = await mount(t, { sport: 'cbb', sortRecentByDate: true });
    x.show.calls[0].resolve(team(1));
    x.metric.calls[0].resolve(metrics());
    await flush();
    const row = (id, start, status = 'STATUS_SCHEDULED') => ({
        id,
        season: 2026,
        game_date: '2026-10-10',
        starts_at: start,
        status,
    });
    x.games.calls[0].resolve({
        data: [
            row(1, '2026-10-10T20:00:00Z'),
            row(2, '2026-10-10T17:00:00Z'),
            row(3, '2026-10-10T21:00:00Z', 'STATUS_FINAL'),
            row(4, '2026-10-10T19:00:00Z'),
            row(5, null),
            row(6, '2026-10-10T19:00:00'),
        ],
    });
    x.roster.calls[0].resolve({ data: [] });
    await flush();
    assert.deepEqual(
        x.state.upcomingGames.value.map((game) => game.id),
        [4, 1, 5, 6],
    );
});

test('team averages and league comparison requests retain the selected metric season and phase', async (t) => {
    const x = await mount(t, {
        seasonStatTiles: [],
        statRankingKeys: [{ key: 'points_per_game' }],
    });
    x.show.calls[0].resolve(team(1));
    x.metric.calls[0].resolve({ data: { season: 2025, season_type: '2' } });
    await flush();
    assert.deepEqual(x.stats.calls[0].args[2].query, {
        season: 2025,
        season_type: '2',
    });
    assert.deepEqual(x.statRanks.calls[0].args[1].query, {
        season: 2025,
        season_type: '2',
    });
    x.games.calls[0].resolve({ data: [] });
    x.roster.calls[0].resolve({ data: [] });
    x.stats.calls[0].resolve({ data: null });
    x.statRanks.calls[0].resolve({ data: [] });
});

test('team completed-game records admit the shared full-time status rather than silently dropping its observed result', async (t) => {
    const x = await mount(t);
    x.show.calls[0].resolve(team(1));
    x.metric.calls[0].resolve(metrics());
    await flush();
    x.games.calls[0].resolve({
        data: [
            {
                id: 1,
                season: 2026,
                status: 'STATUS_FULL_TIME',
                home_team_id: 1,
                away_team_id: 2,
                home_score: 10,
                away_score: 0,
                game_date: '2026-10-10',
                starts_at: '2026-10-10T18:00:00Z',
            },
        ],
    });
    x.roster.calls[0].resolve({ data: [] });
    await flush();
    assert.deepEqual(x.state.record.value, {
        wins: 1,
        losses: 0,
        ties: 0,
        games: 1,
    });
});

test('team metric ranks use the same latest calculation-date and ID policy as the displayed team metric', async (t) => {
    const x = await mount(t, {
        showPowerRanking: true,
        metricRankingKeys: [{ key: 'net_rating' }],
    });
    x.show.calls[0].resolve(team(1));
    x.metric.calls[0].resolve({
        data: {
            id: 3,
            team_id: 1,
            season: 2026,
            season_type: '2',
            calculation_date: '2026-10-10T00:00:00-05:00',
            net_rating: 20,
        },
    });
    await flush();
    x.games.calls[0].resolve({ data: [] });
    x.roster.calls[0].resolve({ data: [] });
    // Valid distinct stored phase aliases; API index order is net rating, not revision recency.
    x.ranks.calls[0].resolve({
        data: [
            {
                id: 3,
                team_id: 1,
                season: 2026,
                season_type: '2',
                calculation_date: '2026-10-10T00:00:00-05:00',
                net_rating: 20,
            },
            {
                id: 4,
                team_id: 2,
                season: 2026,
                calculation_date: '2026-10-10',
                net_rating: 15,
            },
            {
                id: 2,
                team_id: 1,
                season: 2026,
                season_type: 'regular',
                calculation_date: '2026-10-10',
                net_rating: 10,
            },
            {
                id: 99,
                team_id: 1,
                season: 2026,
                season_type: 'Regular Season',
                calculation_date: '2026-10-09',
                net_rating: -50,
            },
        ],
    });
    await flush();
    assert.deepEqual(x.state.powerRanking.value, { rank: 1, total_teams: 2 });
    assert.equal(x.state.metricRankings.value.net_rating, 1);
    assert.equal(x.state.metricRankingSamples.value.net_rating, 2);
});
