export type ExportCoverRecord = {
    games: number;
    hits?: number;
    over?: number;
    under?: number;
    pushes?: number;
    wins?: number;
    recommendation?: 'Over' | 'Under';
};

export type Recommendation = {
    id: number;
    player: {
        name: string;
        position: string | null;
        team: string | null;
        headshot: string | null;
    };
    market: string;
    line: number;
    recommendation: 'Over' | 'Under';
    odds: number;
    confidence: number | null;
    edge: number | null;
    stats: {
        season_avg: number | null;
        recent_avg: number | null;
        last5_avg: number | null;
        times_covered_last5: ExportCoverRecord | null;
        times_covered_season: ExportCoverRecord | null;
        cover_record?: {
            last_5?: ExportCoverRecord | null;
            season?: ExportCoverRecord | null;
        } | null;
        vs_opponent_avg: number | null;
        consistency: {
            std_dev: number;
            level: string;
            min: number;
            max: number;
        } | null;
    };
    model_over_probability?: number | null;
    market_over_probability?: number | null;
    game: {
        home_team: string | null;
        away_team: string | null;
        date: string | null;
        time: string | null;
        starts_at?: string | null;
    };
    bookmaker: string | null;
};

export type Option = {
    value: string;
    label: string;
};

export type GameOption = {
    id: number;
    label: string;
    date: string | null;
    time?: string | null;
    starts_at?: string | null;
};

export type ExportPreset = {
    id: 'instagram' | 'facebook';
    label: string;
    width: number;
    height: number;
};

export type StatRow = {
    key: string;
    label: string;
    value: string;
};

export type PredictionExport = {
    id: number | string;
    game_id: number;
    home_team: string;
    away_team: string;
    game: {
        home_team: string | null;
        away_team: string | null;
        date: string | null;
        time: string | null;
        starts_at?: string | null;
    };
    predicted_spread: number | string | null;
    predicted_total: number | string | null;
    win_probability: number | null;
    confidence: number | null;
    home_elo: number | string | null;
    away_elo: number | string | null;
    pick_side: 'Home' | 'Away' | null;
    pick_team: string | null;
};

export type FuturesExport = {
    id: number;
    team: string;
    season: number;
    projected_seed: number | null;
    playoff_make_probability: number;
    champion_probability: number;
    conference_or_league: string | null;
    conference_finals_probability: number | null;
    nba_finals_probability: number | null;
    world_series_probability: number | null;
    league_championship_probability: number | null;
    sport: 'NBA' | 'MLB';
};

export type TournamentExport = {
    id: number;
    team: string;
    season: number;
    conference: string | null;
    projected_seed: number | null;
    tournament_make_probability: number;
    champion_probability: number;
    auto_bid_probability: number;
    at_large_probability: number;
    first_four_probability: number;
    bid_thief_probability: number;
};

export type AdminExportProps = {
    sport: 'NBA' | 'NFL' | 'MLB' | 'CBB';
    activeTab: 'props' | 'predictions' | 'futures' | 'tournament';
    recommendations: Recommendation[];
    predictions: PredictionExport[];
    futures: FuturesExport[];
    tournaments: TournamentExport[];
    dates: Option[];
    games: GameOption[];
    markets: Option[];
    predictionDates: Option[];
    predictionGames: GameOption[];
    futuresSeasons: Option[];
    tournamentSeasons: Option[];
    filters: {
        sport: 'NBA' | 'NFL' | 'MLB' | 'CBB';
        date: string | null;
        game: number | null;
        market: string | null;
        tab: 'props' | 'predictions' | 'futures' | 'tournament';
        season: number | null;
    };
};
