import type { Recommendation } from '@/lib/playerPropTypes';

export type DateOption = {
    value: string;
    label: string;
};

export type GameOption = {
    id: number;
    label: string;
    date: string;
    time: string;
};

export type MarketOption = {
    value: string;
    label: string;
};

export type PlayerPropsFilters = {
    view?: 'recommendations' | 'all';
    date: string | null;
    game: string | number | null;
    market: string | null;
};

export type PlayerPropsMeta = {
    diagnostics?: {
        raw_prop_count?: number;
        analyzed_prop_count?: number;
        recommendation_candidate_count?: number;
        missing_player_link_count?: number;
    };
    warnings?: string[];
};

export type PlayerPropsResponse = {
    sport: string;
    data: Recommendation[];
    dates: DateOption[];
    games: GameOption[];
    markets: MarketOption[];
    filters: PlayerPropsFilters;
    meta?: PlayerPropsMeta;
};

export type PlayerPropsPageProps = {
    sport: string;
    sportSlug: string;
    filters: PlayerPropsFilters;
    sportLabel: string;
    description: string;
};
