import type { UrlMethodPair } from '@inertiajs/core';
import type { ApiV2Query, ApiV2SportSlug } from '@/types';

export type HrefLike = string | UrlMethodPair;

export interface Player {
    id: number;
    team_id: number;
    first_name: string | null;
    last_name: string | null;
    full_name?: string | null;
    name: string;
    jersey_number: string | null;
    position: string | null;
    height: string | null;
    weight: string | null;
    headshot_url: string | null;
    active_injuries_count?: number;
    active_injuries?: Array<{
        id: number;
        status: string | null;
        detail: string | null;
        type: string | null;
        return_date: string | null;
        source_updated_at?: string | null;
    }>;
    team: {
        id: number;
        name: string;
        display_name: string;
        abbreviation: string;
    } | null;
}

export interface PlayerProp {
    id: number;
    market: string;
    line: number;
    over_price: number;
    under_price: number;
    game: {
        id: number;
        home_team: string;
        away_team: string;
    };
}

export interface PlayerStat {
    id: number;
    [key: string]: number | string | null | undefined | PlayerStat['game'];
    game: {
        id: number;
        game_date: string | null;
        home_team_id: number;
        away_team_id: number;
        home_team: { abbreviation: string; name: string } | null;
        away_team: { abbreviation: string; name: string } | null;
    } | null;
}

export interface SummaryCard {
    label: string;
    value: (stats: PlayerStat[]) => string;
    rankKey?: string;
}

export interface GameLogColumn {
    label: string;
    value: (stat: PlayerStat) => string | number;
    className?: string;
}

export interface SportPlayerShellConfig {
    sport: ApiV2SportSlug;
    sportLabel: string;
    predictionsHref: string;
    teamLink?: (id: number) => HrefLike;
    gameLink?: (id: number) => HrefLike;
    showPlayerProps?: boolean;
    statsQuery?: ApiV2Query;
    leaderboardQuery?: ApiV2Query;
    summaryCards?: SummaryCard[];
    gameLogColumns?: GameLogColumn[];
}

export type PlayerPageProps = {
    config: SportPlayerShellConfig;
    playerId: number;
};

export interface PlayerSummaryValue {
    label: string;
    value: string;
    rank: { rank: number; total: number } | null;
}
