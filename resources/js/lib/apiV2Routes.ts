// Public API paths are a frontend contract; no PHP or Wayfinder is required at build time.
export const apiV2Routes = {
    'admin.nfl.researchRetry.show':
        '/api/v2/admin/nfl/games/{game}/research-retry',
    'admin.nfl.researchRetry.store':
        '/api/v2/admin/nfl/games/{game}/research-retry',
    'admin.payloadInspector': '/api/v2/admin/payload-inspector',
    'alertPreferences.show': '/api/v2/alert-preferences',
    'alertPreferences.store': '/api/v2/alert-preferences',
    'alertPreferences.update': '/api/v2/alert-preferences',
    'cbbBrackets.current.show': '/api/v2/cbb-brackets/current',
    'cbbBrackets.current.upsert': '/api/v2/cbb-brackets/current',
    'cbbBrackets.destroy': '/api/v2/cbb-brackets/{publicId}',
    'cbbBrackets.index': '/api/v2/cbb-brackets',
    'cbbBrackets.leaderboard': '/api/v2/cbb-brackets/leaderboard',
    'cbbBrackets.show': '/api/v2/cbb-brackets/{publicId}',
    'cbbBrackets.store': '/api/v2/cbb-brackets',
    'cbbBrackets.update': '/api/v2/cbb-brackets/{publicId}',
    'groups.index': '/api/v2/groups',
    'groups.store': '/api/v2/groups',
    'groups.update': '/api/v2/groups/{publicId}',
    'liveScoreboard.show': '/api/v2/live-scoreboard',
    'sports.forecasts.index': '/api/v2/sports/{sport}/forecasts',
    'sports.games.depthCharts.show':
        '/api/v2/sports/{sport}/games/{game}/depth-charts',
    'sports.games.index': '/api/v2/sports/{sport}/games',
    'sports.games.liveSnapshot.show':
        '/api/v2/sports/{sport}/games/{game}/live-snapshot',
    'sports.games.marketHistory.show':
        '/api/v2/sports/{sport}/games/{game}/market-history',
    'sports.games.matchupSignals.show':
        '/api/v2/sports/{sport}/games/{game}/matchup-signals',
    'sports.games.page.show': '/api/v2/sports/{sport}/games/{game}/page',
    'sports.games.playerProps.index':
        '/api/v2/sports/{sport}/games/{game}/player-props',
    'sports.games.prediction.show':
        '/api/v2/sports/{sport}/games/{game}/prediction',
    'sports.games.research.show':
        '/api/v2/sports/{sport}/games/{game}/research',
    'sports.games.show': '/api/v2/sports/{sport}/games/{game}',
    'sports.games.trends.show': '/api/v2/sports/{sport}/games/{game}/trends',
    'sports.index': '/api/v2/sports',
    'sports.injuries.index': '/api/v2/sports/{sport}/injuries',
    'sports.leaderboards.players.availableSeasons':
        '/api/v2/sports/{sport}/leaderboards/players/available-seasons',
    'sports.leaderboards.players.index':
        '/api/v2/sports/{sport}/leaderboards/players',
    'sports.markets.futures.index': '/api/v2/sports/{sport}/markets/futures',
    'sports.markets.playerProps.index':
        '/api/v2/sports/{sport}/markets/player-props',
    'sports.metrics.teams.availableSeasons':
        '/api/v2/sports/{sport}/metrics/teams/available-seasons',
    'sports.metrics.teams.index': '/api/v2/sports/{sport}/metrics/teams',
    'sports.playerProps.board': '/api/v2/sports/{sport}/player-props/board',
    'sports.playerProps.index': '/api/v2/sports/{sport}/player-props',
    'sports.players.index': '/api/v2/sports/{sport}/players',
    'sports.players.playerProps.index':
        '/api/v2/sports/{sport}/players/{player}/player-props',
    'sports.players.show': '/api/v2/sports/{sport}/players/{player}',
    'sports.predictions.availableDates':
        '/api/v2/sports/{sport}/predictions/available-dates',
    'sports.predictions.availableSeasons':
        '/api/v2/sports/{sport}/predictions/available-seasons',
    'sports.predictions.index': '/api/v2/sports/{sport}/predictions',
    'sports.predictions.show':
        '/api/v2/sports/{sport}/predictions/{prediction}',
    'sports.show': '/api/v2/sports/{sport}',
    'sports.signals.index': '/api/v2/sports/{sport}/signals',
    'sports.stats.player.availableDates':
        '/api/v2/sports/{sport}/stats/player/available-dates',
    'sports.stats.player.availableSeasons':
        '/api/v2/sports/{sport}/stats/player/available-seasons',
    'sports.stats.player.index': '/api/v2/sports/{sport}/stats/player',
    'sports.stats.team.availableDates':
        '/api/v2/sports/{sport}/stats/team/available-dates',
    'sports.stats.team.availableSeasons':
        '/api/v2/sports/{sport}/stats/team/available-seasons',
    'sports.stats.team.index': '/api/v2/sports/{sport}/stats/team',
    'sports.stats.team.seasonAverages.index':
        '/api/v2/sports/{sport}/stats/team/season-averages',
    'sports.teams.depthCharts.show':
        '/api/v2/sports/{sport}/teams/{team}/depth-charts',
    'sports.teams.futures.index': '/api/v2/sports/{sport}/teams/{team}/futures',
    'sports.teams.games.index': '/api/v2/sports/{sport}/teams/{team}/games',
    'sports.teams.index': '/api/v2/sports/{sport}/teams',
    'sports.teams.metrics.show': '/api/v2/sports/{sport}/teams/{team}/metrics',
    'sports.teams.players.index': '/api/v2/sports/{sport}/teams/{team}/players',
    'sports.teams.show': '/api/v2/sports/{sport}/teams/{team}',
    'sports.teams.stats.seasonAverages.show':
        '/api/v2/sports/{sport}/teams/{team}/stats/season-averages',
    'sports.teams.trends.show': '/api/v2/sports/{sport}/teams/{team}/trends',
    'userBets.destroy': '/api/v2/user-bets/{bet}',
    'userBets.export': '/api/v2/user-bets/export',
    'userBets.index': '/api/v2/user-bets',
    'userBets.store': '/api/v2/user-bets',
    'userBets.update': '/api/v2/user-bets/{bet}',
} as const;

export type Query = {
    [key: string]:
        | string
        | number
        | boolean
        | null
        | undefined
        | (string | number)[]
        | Query;
};
type Options = { query?: Query; mergeQuery?: Query };
type Params =
    | string
    | number
    | readonly (string | number)[]
    | Record<string, unknown>;
export type RouteQueryOptions = Options;

export function apiRoute(
    name: keyof typeof apiV2Routes,
    args?: Params | Options,
    options?: Options,
): string {
    const template = apiV2Routes[name];
    const keys = [...template.matchAll(/\{(\w+)\}/g)].map((match) => match[1]);
    const queryOptions = keys.length ? options : (args as Options | undefined);
    const values = Array.isArray(args)
        ? Object.fromEntries(keys.map((key, index) => [key, args[index]]))
        : typeof args === 'object' && args !== null
          ? (args as Record<string, unknown>)
          : { [keys[0]]: args };
    const path = template.replace(/\{(\w+)\}/g, (_, key: string) => {
        const value = (values as Record<string, unknown>)[key];
        if (value == null) throw new Error(`Missing API parameter: ${key}`);
        return encodeURIComponent(String(value));
    });
    const query = new URLSearchParams();
    const append = (key: string, value: Query[string]) => {
        if (value == null) return;
        if (Array.isArray(value))
            value.forEach((item) => query.append(`${key}[]`, String(item)));
        else if (typeof value === 'object')
            Object.entries(value).forEach(([child, item]) =>
                append(`${key}[${child}]`, item),
            );
        else
            query.set(
                key,
                typeof value === 'boolean'
                    ? value
                        ? '1'
                        : '0'
                    : String(value),
            );
    };
    Object.entries(
        queryOptions?.query ?? queryOptions?.mergeQuery ?? {},
    ).forEach(([key, value]) => append(key, value));
    return path + (query.size ? `?${query}` : '');
}
