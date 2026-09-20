export type CoverRecord = {
    games: number;
    over: number;
    under: number;
    pushes: number;
    hits: number;
    recommendation: 'Over' | 'Under';
    wins: number;
    losses: number;
    win_rate: number | null;
    record: string;
    recommendation_record: string;
};

export type Recommendation = {
    id: number;
    player: {
        id: number | null;
        name: string;
        position: string | null;
        team: string | null;
        headshot: string | null;
        url: string | null;
    };
    market: string;
    line: number;
    recommendation: 'Over' | 'Under';
    odds: number | null;
    confidence: number;
    stats: {
        season_avg: number | null;
        historical_avg?: number | null;
        season_summary?: {
            season: number | null;
            average: number | null;
            games: number;
            missing_stat_games: number;
            opponent?: string | null;
            opponent_conference?: string | null;
        } | null;
        recent_avg: number | null;
        last5_avg: number | null;
        times_covered_last5: { hits: number; games: number } | null;
        times_covered_season: { hits: number; games: number } | null;
        cover_record: {
            season: CoverRecord | null;
            historical_last_17?: CoverRecord | null;
            last_season?: CoverRecord | null;
            all_time?: CoverRecord | null;
            vs_conference?: CoverRecord | null;
            last_10: CoverRecord | null;
            last_5: CoverRecord | null;
            home_away: CoverRecord | null;
            vs_opponent: CoverRecord | null;
        } | null;
        vs_opponent_avg: number | null;
        consistency: {
            std_dev: number;
            level: string;
            min: number;
            max: number;
        } | null;
    };
    streak: { count: number; type: string; status: string } | null;
    edge: number;
    model_over_probability?: number | null;
    market_over_probability?: number | null;
    edge_probability?: number | null;
    context?: {
        pace_factor: number;
        opponent_factor: number;
        minutes_factor: number;
        combined_factor: number;
    } | null;
    data_quality_score?: number | null;
    match_quality_score?: number | null;
    confidence_decomposition?: {
        schema_version?: string;
        probability_method?: string;
        probability_basis?: string;
        push_probability?: number;
        history?: {
            basis: string;
            current_season_games: number;
            model_season: number;
        };
        model_edge_score: number;
        data_quality_score: number;
        match_quality_score: number;
        context_factor: number;
        signal_quality?: {
            label?: string;
            tier?: string;
            reason_codes?: string[];
        };
    } | null;
    actual_value?: number | null;
    hit_over?: boolean | null;
    graded_at?: string | null;
    reasoning: string[];
    game: {
        id: number;
        home_team: string;
        away_team: string;
        date: string;
        time: string;
        starts_at?: string | null;
        kickoff_label?: string;
        timezone?: string;
    };
    bookmaker: string;
    fetched_at?: string | null;
    freshness_hours?: number;
};
