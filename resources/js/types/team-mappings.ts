type Team = {
    id: number;
    name: string;
    abbreviation: string;
    mascot?: string | null;
};

export type Mapping = {
    id: number;
    external_team_name?: string | null;
    external_team_abbreviation?: string | null;
    external_team_id?: number | null;
    espn_team_name?: string | null;
    suggested_espn_team_name?: string | null;
    odds_api_team_name?: string;
    espn_player_name?: string | null;
    espn_player_id?: number | null;
    odds_api_player_name?: string;
    suggested_espn_player_name?: string | null;
    suggested_player_id?: number | null;
    suggested_match_quality_score?: number | null;
    sport: string;
};

type Sport = {
    key: string;
    label: string;
};

type Provider = {
    key: string;
    label: string;
};

export type TeamMappingsProps = {
    mappings: {
        data: Mapping[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    espnTeams: Team[];
    currentSport: string;
    currentFilter: string;
    currentProvider?: string;
    entityType?: 'team' | 'player';
    indexBase?: string;
    mutationBase?: string;
    queryParams?: Record<string, string>;
    pageTitle?: string;
    pageDescription?: string;
    externalSourceLabel?: string;
    emptyStateCommand?: string | null;
    stats: {
        total: number;
        mapped: number;
        unmapped: number;
    };
    sports: Sport[];
    providers?: Provider[];
};
