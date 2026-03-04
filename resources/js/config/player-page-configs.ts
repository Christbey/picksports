import NBAGameController from '@/actions/App/Http/Controllers/NBA/GameController';
import NBATeamController from '@/actions/App/Http/Controllers/NBA/TeamController';

export const nbaPlayerPageConfig = {
    sportLabel: 'NBA',
    predictionsHref: '/nba-predictions',
    teamLink: (id: number) => NBATeamController.url(id),
    gameLink: (id: number) => NBAGameController.url(id),
    statsEndpoint: (playerId: number) => `/api/v1/nba/players/${playerId}/stats`,
};

export const cbbPlayerPageConfig = {
    sportLabel: 'CBB',
    predictionsHref: '/cbb-predictions',
    teamLink: (id: number) => `/cbb/teams/${id}`,
    gameLink: (id: number) => `/cbb/games/${id}`,
    statsEndpoint: (playerId: number) => `/api/v1/cbb/players/${playerId}/stats`,
};
