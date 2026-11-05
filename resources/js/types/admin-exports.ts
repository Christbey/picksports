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
    confidence: number;
    edge: number;
    stats: {
        season_avg: number;
        recent_avg: number;
        last5_avg: number;
        times_covered_last5: { hits: number; games: number } | null;
        times_covered_season: { hits: number; games: number } | null;
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
    date: string;
    time: string;
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
    id: number;
    game_id: number;
    home_team: string;
    away_team: string;
    game: {
        home_team: string | null;
        away_team: string | null;
        date: string | null;
        time: string | null;
    };
    predicted_spread: number | null;
    predicted_total: number | null;
    win_probability: number;
    confidence: number;
    home_elo: number | null;
    away_elo: number | null;
    pick_side: 'Home' | 'Away';
    pick_team: string;
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
