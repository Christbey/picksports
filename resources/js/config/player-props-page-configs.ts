export type PlayerPropsPageConfig = {
    sportLabel: string;
    description: string;
    sportSlug: string;
};

const configs: Record<string, PlayerPropsPageConfig> = {
    NBA: {
        sportLabel: 'NBA',
        description: 'Data-driven player prop bets based on statistical analysis and recent form',
        sportSlug: 'nba',
    },
    CBB: {
        sportLabel: 'College Basketball',
        description: 'Data-driven player prop bets based on statistical analysis and recent form',
        sportSlug: 'cbb',
    },
    NFL: {
        sportLabel: 'NFL',
        description: 'Data-driven player prop bets based on statistical analysis and recent form',
        sportSlug: 'nfl',
    },
    MLB: {
        sportLabel: 'MLB',
        description: 'Data-driven player prop bets based on statistical analysis and recent form',
        sportSlug: 'mlb',
    },
};

export const getPlayerPropsPageConfig = (sport: string): PlayerPropsPageConfig =>
    configs[sport] ?? {
        sportLabel: sport,
        description: 'Data-driven player prop bets based on statistical analysis and recent form',
        sportSlug: sport.toLowerCase(),
    };
