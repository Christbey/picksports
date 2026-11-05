import type { UrlMethodPair } from '@inertiajs/core';
import type { ApiV2SportSlug, BreadcrumbItem } from '@/types';

export type HrefLike = string | UrlMethodPair;

export interface PlayerLeaderboardEntry {
    player_id: number;
    player: {
        id: number;
        full_name: string;
        headshot_url: string | null;
        position: string | null;
        jersey_number: string | null;
        team: {
            id: number;
            name: string;
            display_name: string;
            abbreviation: string;
        } | null;
    } | null;
    games_played: number;
    points_per_game: number;
    rebounds_per_game: number;
    assists_per_game: number;
    steals_per_game: number;
    blocks_per_game: number;
    minutes_per_game: number;
    field_goal_percentage: number;
    three_point_percentage: number;
    free_throw_percentage: number;
    estimated_epa_per_game?: number;
    estimated_epa_per_36?: number;
    estimated_epa_total?: number;
    estimated_epa_per_opportunity?: number;
    passing_yards_per_game?: number;
    passing_touchdowns_per_game?: number;
    completion_percentage?: number;
    interceptions_thrown_per_game?: number;
    rushing_yards_per_game?: number;
    rushing_touchdowns_per_game?: number;
    yards_per_carry?: number;
    receptions_per_game?: number;
    receiving_yards_per_game?: number;
    tackles_per_game?: number;
    sacks_per_game?: number;
    def_interceptions_per_game?: number;
    passes_defended_per_game?: number;
    fumbles_recovered_per_game?: number;
    field_goals_made_per_game?: number;
    extra_points_made_per_game?: number;
    field_goal_percentage_special?: number;
    extra_point_percentage?: number;
}

export interface SortOption {
    key: string;
    label: string;
}

export interface StatColumn {
    key: string;
    label: string;
    cellClass?: string;
    format?: (
        value: number | undefined,
        entry: PlayerLeaderboardEntry,
    ) => string;
}

export interface StatCategoryOption {
    key: string;
    label: string;
    minGames?: number;
    defaultSortBy?: string;
    sortOptions?: SortOption[];
    statColumns?: StatColumn[];
    match?: (entry: PlayerLeaderboardEntry) => boolean;
}

export interface SportPlayerStatsShellConfig {
    sport: ApiV2SportSlug;
    pageTitle: string;
    heading: string;
    description: string;
    breadcrumb: BreadcrumbItem;
    bannerStorageKey: string;
    showEpaColumns?: boolean;
    sortOptions?: SortOption[];
    statColumns?: StatColumn[];
    statCategoryOptions?: StatCategoryOption[];
    minGames?: number;
    seasonTypeOptions?: Array<{ value: string; label: string }>;
    defaultSeasonType?: string;
    playerLink?: (id: number) => HrefLike;
    teamLink?: (id: number) => HrefLike;
}

export type PlayerStatsProps = { config: SportPlayerStatsShellConfig };
export type NumericLeaderboardKey = Exclude<
    keyof PlayerLeaderboardEntry,
    'player'
>;
