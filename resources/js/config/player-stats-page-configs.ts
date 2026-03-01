import CBBTeamController from '@/actions/App/Http/Controllers/CBB/TeamController';
import NBAPlayerController from '@/actions/App/Http/Controllers/NBA/PlayerController';

export const nbaPlayerStatsPageConfig = {
    pageTitle: 'NBA Player Stats',
    heading: 'NBA Player Stats',
    description: 'Season averages leaderboard for NBA players',
    breadcrumb: { title: 'NBA Player Stats', href: '/nba-player-stats' },
    bannerStorageKey: 'nba-player-stats-banner-dismissed',
    leaderboardEndpoint: '/api/v1/nba/player-stats/leaderboard',
    showEpaColumns: true,
    playerLink: (id: number) => NBAPlayerController(id),
};

export const cbbPlayerStatsPageConfig = {
    pageTitle: 'CBB Player Stats',
    heading: 'CBB Player Stats',
    description: 'Season averages leaderboard for college basketball players',
    breadcrumb: { title: 'CBB Player Stats', href: '/cbb-player-stats' },
    bannerStorageKey: 'cbb-player-stats-banner-dismissed',
    leaderboardEndpoint: '/api/v1/cbb/player-stats/leaderboard',
    showEpaColumns: true,
    playerLink: (id: number) => `/cbb/players/${id}`,
    teamLink: (id: number) => CBBTeamController(id),
};
