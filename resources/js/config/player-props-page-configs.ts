export type PlayerPropsPageConfig = {
    sportLabel: string;
    description: string;
};

const configs: Record<string, PlayerPropsPageConfig> = {
    NBA: {
        sportLabel: 'NBA',
        description: 'Data-driven player prop bets based on statistical analysis and recent form',
    },
    CBB: {
        sportLabel: 'College Basketball',
        description: 'Data-driven player prop bets based on statistical analysis and recent form',
    },
    NFL: {
        sportLabel: 'NFL',
        description: 'Data-driven player prop bets based on statistical analysis and recent form',
    },
    MLB: {
        sportLabel: 'MLB',
        description: 'Data-driven player prop bets based on statistical analysis and recent form',
    },
};

export const getPlayerPropsPageConfig = (sport: string): PlayerPropsPageConfig =>
    configs[sport] ?? {
        sportLabel: sport,
        description: 'Data-driven player prop bets based on statistical analysis and recent form',
    };
