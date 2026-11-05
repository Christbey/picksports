export type InjuryItem = {
    id: number;
    player_name: string;
    team_abbreviation?: string | null;
    status?: string | null;
    detail?: string | null;
    type?: string | null;
    injury_date?: string | null;
    return_date?: string | null;
    source_updated_at?: string | null;
    updated_at?: string | null;
    impact_score?: number | null;
    impact_label?: string | null;
};
export type TopTeam = {
    rank: number;
    team_id?: number | null;
    name: string;
    abbreviation?: string | null;
    logo?: string | null;
    record?: string | null;
    primary_metric_label: string;
    primary_metric_value?: number | string | null;
    net_rating?: number | string | null;
};
export type TopPlayer = {
    player_id?: number | null;
    name: string;
    team_abbreviation?: string | null;
    position?: string | null;
    headshot?: string | null;
    games_played?: number | null;
    headline_stat_label: string;
    headline_stat_value?: number | string | null;
};
export type FeaturedPrediction = {
    id: number | string;
    game_id?: number | null;
    matchup: string;
    game_date?: string | null;
    status?: string | null;
    pick: string | null;
    home_team_abbreviation?: string | null;
    away_team_abbreviation?: string | null;
    predicted_spread?: number | string | null;
    predicted_total?: number | string | null;
    confidence_score?: number | string | null;
    home_win_probability?: number | string | null;
};
export type FeaturedProp = {
    id: number;
    player: {
        id: number;
        name: string;
        position?: string | null;
        team?: string | null;
        headshot?: string | null;
        url?: string | null;
    };
    market: string;
    line: number;
    recommendation: string;
    odds: number;
    confidence: number;
    edge: number;
    stats?: {
        times_covered_last5?: { hits: number; games: number } | null;
        times_covered_season?: { hits: number; games: number } | null;
    } | null;
    game: {
        id: number;
        home_team?: string | null;
        away_team?: string | null;
        date?: string | null;
        time?: string | null;
    };
};
export type ConferencePlayoffTeam = {
    team_id: number;
    seed?: number | null;
    projected_seed?: number | null;
    name: string;
    abbreviation?: string | null;
    logo?: string | null;
    conference: string;
    playoff_make_probability?: number | null;
    direct_playoff_probability?: number | null;
};
export type PublicSportProps = {
    sport: string;
    sportLabel: string;
    latestSeason?: number | null;
    hasPlayerProps: boolean;
    conferencePlayoffTeams?: {
        east: ConferencePlayoffTeam[];
        west: ConferencePlayoffTeam[];
    } | null;
    injuries: {
        top: InjuryItem[];
        recent: InjuryItem[];
    };
    topTeams: TopTeam[];
    topPlayers: TopPlayer[];
    featuredPredictions: FeaturedPrediction[];
    featuredProps: FeaturedProp[];
    links: {
        predictions: string;
        injuries: string | null;
        teamMetrics: string | null;
        playerStats: string | null;
        playerProps: string | null;
    };
    summary: {
        topInjuriesCount: number;
        recentInjuriesCount: number;
        topTeamsCount: number;
        topPlayersCount: number;
        predictionsCount: number;
        propsCount: number;
    };
};
